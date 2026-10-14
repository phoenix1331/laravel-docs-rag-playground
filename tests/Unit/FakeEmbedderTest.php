<?php

declare(strict_types=1);

use App\Services\Embeddings\FakeEmbedder;

it('returns the same vector for the same text', function () {
    $embedder = new FakeEmbedder(64);

    expect($embedder->embedQuery('Dispatching job batches'))
        ->toBe($embedder->embedQuery('Dispatching job batches'));
});

it('returns unit vectors of the configured length', function () {
    $vector = (new FakeEmbedder(64))->embedQuery('Queues and workers');

    expect($vector)->toHaveCount(64)
        ->and(sqrt(cosineSimilarity($vector, $vector)))->toEqualWithDelta(1.0, 0.000001);
});

it('scores texts that share words as more similar', function () {
    $embedder = new FakeEmbedder(256);
    [$batches, $routing] = $embedder->embedDocuments(['Dispatch a batch of queued jobs', 'Define a route with parameters']);
    $query = $embedder->embedQuery('How do I dispatch a batch of jobs?');

    expect(cosineSimilarity($query, $batches))->toBeGreaterThan(cosineSimilarity($query, $routing));
});

it('handles text with no words', function () {
    expect((new FakeEmbedder(16))->embedQuery('###'))->toHaveCount(16);
});
