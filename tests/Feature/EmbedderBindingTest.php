<?php

declare(strict_types=1);

use App\Contracts\Embedder;
use App\Services\Embeddings\AiSdkEmbedder;
use App\Services\Embeddings\FakeEmbedder;

function resolveEmbedder(array $config): Embedder
{
    config()->set('rag.embedding', array_merge(config('rag.embedding'), $config));
    app()->forgetInstance(Embedder::class);

    return app(Embedder::class);
}

it('uses the provider default model when none is configured', function (string $provider, string $model) {
    $embedder = resolveEmbedder(['provider' => $provider, 'model' => null]);

    expect($embedder)->toBeInstanceOf(AiSdkEmbedder::class)
        ->and($embedder->model())->toBe($model);
})->with([
    ['voyageai', 'voyage-4'],
    ['openai', 'text-embedding-3-small'],
]);

it('uses the configured model when set', function () {
    expect(resolveEmbedder(['provider' => 'voyageai', 'model' => 'voyage-code-4'])->model())->toBe('voyage-code-4');
});

it('runs offline with the fake provider', function () {
    $embedder = resolveEmbedder(['provider' => 'fake', 'dimensions' => 32]);

    expect($embedder)->toBeInstanceOf(FakeEmbedder::class)
        ->and($embedder->dimensions())->toBe(32);
});
