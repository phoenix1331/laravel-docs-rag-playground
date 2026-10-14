<?php

declare(strict_types=1);

use App\Contracts\Embedder;
use App\Services\Embeddings\AiSdkEmbedder;
use Laravel\Ai\Embeddings;
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
