<?php

declare(strict_types=1);

use App\Contracts\Embedder;
use App\Models\DocChunk;

beforeEach(function () {
    foreach ([
        ['13.x', 'job-batching', 'Queues > Job Batching', 'Dispatch a batch of jobs and run a callback when the batch finishes'],
        ['13.x', 'basic-routing', 'Routing > Basic Routing', 'Define a route using the Route facade'],
        ['12.x', 'job-batching', 'Queues > Job Batching', 'Dispatch a batch of jobs in Laravel 12'],
    ] as [$version, $anchor, $path, $content]) {
        DocChunk::factory()->version($version)->create([
            'anchor' => $anchor,
            'heading_path' => $path,
            'content' => $content,
            'embedding' => app(Embedder::class)->embedDocuments([$content])[0],
        ]);
    }
});

it('prints ranked results with scores for the default version', function () {
    $this->artisan('docs:search', ['query' => 'dispatch a batch of jobs'])
        ->expectsOutputToContain('vector search in 13.x, top 2')
        ->expectsOutputToContain('Queues > Job Batching')
        ->doesntExpectOutputToContain('/docs/12.x/')
        ->assertSuccessful();
});

it('searches another version and limits results', function () {
    $this->artisan('docs:search', ['query' => 'batch', '--docs-version' => '12.x', '--k' => 1])
        ->expectsOutputToContain('vector search in 12.x, top 1')
        ->assertSuccessful();
});

it('can print chunk content', function () {
    $this->artisan('docs:search', ['query' => 'route facade', '--content' => true])
        ->expectsOutputToContain('Define a route using the Route facade')
        ->assertSuccessful();
});

it('warns when nothing passes the similarity threshold', function () {
    config()->set('rag.retrieval.min_similarity', 0.99);

    $this->artisan('docs:search', ['query' => 'completely unrelated words here'])
        ->expectsOutputToContain('not covered by the docs')
        ->assertSuccessful();
});

it('fails clearly when a version has not been ingested', function () {
    DocChunk::query()->delete();

    $this->artisan('docs:search', ['query' => 'batch'])
        ->expectsOutputToContain('Run docs:ingest 13.x first')
        ->assertFailed();
});

it('shows where each section ranked in vector and keyword search in hybrid mode', function () {
    $this->artisan('docs:search', ['query' => 'dispatch a batch of jobs', '--mode' => 'hybrid'])
        ->expectsOutputToContain('hybrid search in 13.x, top 2')
        ->expectsOutputToContain('Keyword #')
        ->assertSuccessful();
});
