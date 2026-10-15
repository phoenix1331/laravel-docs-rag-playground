<?php

declare(strict_types=1);

use App\Contracts\Embedder;
use App\Services\Embeddings\AiSdkEmbedder;
use App\Services\Embeddings\EmbeddingThrottle;
use Illuminate\Support\Sleep;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

it('binds the fake embedder in tests', function () {
    expect(app(Embedder::class)->model())->toBe('fake');
});

it('embeds documents in batches and preserves order', function () {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(
        fn (string $input) => array_fill(0, 4, (float) strlen($input)),
        $prompt->inputs,
    ));

    $vectors = (new AiSdkEmbedder('voyageai', 'voyage-4', 4, batchSize: 2))
        ->embedDocuments(['a', 'bb', 'ccc']);

    expect(array_column($vectors, 0))->toBe([1.0, 2.0, 3.0]);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->count() === 2);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->count() === 1);
});

it('tags voyage requests as document or query', function () {
    Embeddings::fake();
    $embedder = new AiSdkEmbedder('voyageai', 'voyage-4', 8);

    $embedder->embedDocuments(['Queues']);
    $embedder->embedQuery('How do queues work?');

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->providerOptions === ['input_type' => 'document']);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->providerOptions === ['input_type' => 'query']);
});

it('embeds several queries in batched requests tagged as queries', function () {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(
        fn (string $input) => array_fill(0, 4, (float) strlen($input)),
        $prompt->inputs,
    ));

    $vectors = (new AiSdkEmbedder('voyageai', 'voyage-4', 4, batchSize: 2))->embedQueries(['a', 'bb', 'ccc']);

    expect(array_column($vectors, 0))->toBe([1.0, 2.0, 3.0]);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->count() === 2
        && $prompt->providerOptions === ['input_type' => 'query']);
});

it('sends no voyage options to openai', function () {
    Embeddings::fake();

    (new AiSdkEmbedder('openai', 'text-embedding-3-small', 8))->embedQuery('Routing');

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->providerOptions === []
        && $prompt->model === 'text-embedding-3-small');
});

it('rejects vectors with the wrong dimensions', function () {
    Embeddings::fake([[[0.1, 0.2]]]);

    (new AiSdkEmbedder('voyageai', 'voyage-4', 8))->embedQuery('Routing');
})->throws(UnexpectedValueException::class, 'Expected 8 dimensions, received 2');

it('caps each request by estimated tokens as well as count', function () {
    $embedder = new AiSdkEmbedder('voyageai', 'voyage-4', 4, batchSize: 10, batchTokens: 50);
    $texts = [str_repeat('a', 120), str_repeat('b', 80), str_repeat('c', 120), 'd'];

    expect(array_map('count', $embedder->batches($texts)))->toBe([2, 2]);
});

it('sends an oversized text on its own rather than dropping it', function () {
    $embedder = new AiSdkEmbedder('voyageai', 'voyage-4', 4, batchTokens: 10);

    expect(array_map('count', $embedder->batches([str_repeat('a', 400), 'b'])))->toBe([1, 1]);
});

it('does not make queries wait for the ingest throttle', function () {
    Embeddings::fake();
    Sleep::fake();
    $throttle = new EmbeddingThrottle(cache()->store(), requestsPerMinute: 1);
    $embedder = new AiSdkEmbedder('voyageai', 'voyage-4', 8, throttle: $throttle);

    $embedder->embedDocuments(['Queues']);
    $embedder->embedQuery('How do queues work?');
    $embedder->embedQuery('And batches?');

    Sleep::assertNeverSlept();
});

it('retries a rate limited query briefly', function () {
    Sleep::fake();
    $calls = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls) {
        if (++$calls === 1) {
            throw RateLimitedException::forProvider('voyageai');
        }

        return [array_fill(0, 8, 0.1)];
    });

    expect((new AiSdkEmbedder('voyageai', 'voyage-4', 8))->embedQuery('Routing'))->toHaveCount(8)
        ->and($calls)->toBe(2);
    Sleep::assertSleptTimes(1);
});
