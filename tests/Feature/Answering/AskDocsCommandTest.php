<?php

declare(strict_types=1);

use App\Ai\Agents\DocsAgent;
use App\Models\DocChunk;
use App\Retrieval\RetrievalResult;
use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;

beforeEach(function () {
    config()->set('rag.retrieval.min_similarity', 0.5);
    config()->set('rag.chat.provider', 'gemini');

    $chunk = DocChunk::factory()->make(['heading_path' => 'Queues > Job Batching', 'url' => 'https://laravel.com/docs/13.x/queues#job-batching']);
    $hits = [new RetrievedChunk($chunk, 1, 0.8, 0.8), new RetrievedChunk(DocChunk::factory()->make(['heading_path' => 'Routing > Basics']), 2, 0.4, 0.4)];

    $retriever = Mockery::mock(Retriever::class);
    $retriever->allows('retrieve')->andReturnUsing(fn (string $q, string $v) => new RetrievalResult($q, $v, 'vector', $hits));
    app()->instance(Retriever::class, $retriever);
});

it('prints the answer and lists the cited sources', function () {
    DocsAgent::fake(['Use Bus::batch() [1].']);

    $this->artisan('docs:ask', ['question' => 'How do I batch jobs?', '--no-stream' => true])
        ->expectsOutputToContain('Use Bus::batch() [1].')
        ->expectsOutputToContain('[1] Queues > Job Batching https://laravel.com/docs/13.x/queues#job-batching')
        ->assertSuccessful();
});

it('streams the answer by default', function () {
    DocsAgent::fake(['Use Bus::batch() [1].']);

    // Streamed text arrives in pieces, so only the sources (written in one call) are asserted.
    $this->artisan('docs:ask', ['question' => 'How do I batch jobs?'])
        ->expectsOutputToContain('[1] Queues > Job Batching')
        ->assertSuccessful();
});

it('shows the retrieved context with cited flags', function () {
    DocsAgent::fake(['Use Bus::batch() [1].']);

    $this->artisan('docs:ask', ['question' => 'How do I batch jobs?', '--context' => true, '--no-stream' => true])
        ->expectsTable(['#', 'Similarity', 'Cited', 'Section'], [
            [1, '0.800', 'yes', 'Queues > Job Batching'],
            [2, '0.400', '', 'Routing > Basics'],
        ])
        ->assertSuccessful();
});

it('explains a refusal made without calling the model', function () {
    config()->set('rag.retrieval.min_similarity', 0.9);
    DocsAgent::fake()->preventStrayPrompts();

    $this->artisan('docs:ask', ['question' => 'Mars?'])
        ->expectsOutputToContain("doesn't appear to be covered")
        ->expectsOutputToContain('best similarity 0.800 below 0.90, no model called')
        ->assertSuccessful();
});
