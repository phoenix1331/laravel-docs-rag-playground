<?php

declare(strict_types=1);

use App\Ingestion\DocFileIndexer;
use App\Ingestion\IndexResult;
use App\Jobs\IngestDocFile;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

it('runs the indexer for its page', function () {
    $indexer = Mockery::mock(DocFileIndexer::class);
    $indexer->expects('index')->with('13.x', 'queues.md')->andReturn(new IndexResult(embedded: 2));

    (new IngestDocFile('13.x', 'queues.md'))->withFakeQueueInteractions()->handle($indexer);
});

it('releases itself with backoff when the provider is rate limited or busy', function (Throwable $exception) {
    $indexer = Mockery::mock(DocFileIndexer::class);
    $indexer->expects('index')->andThrow($exception);

    $job = (new IngestDocFile('13.x', 'queues.md'))->withFakeQueueInteractions();
    $job->handle($indexer);

    $job->assertReleased(delay: 15);
})->with([
    'rate limited' => fn () => RateLimitedException::forProvider('voyageai'),
    'overloaded' => fn () => ProviderOverloadedException::forProvider('voyageai'),
]);

it('lets other errors fail the job', function () {
    $indexer = Mockery::mock(DocFileIndexer::class);
    $indexer->expects('index')->andThrow(new RuntimeException('Invalid API key'));

    (new IngestDocFile('13.x', 'queues.md'))->withFakeQueueInteractions()->handle($indexer);
})->throws(RuntimeException::class, 'Invalid API key');
