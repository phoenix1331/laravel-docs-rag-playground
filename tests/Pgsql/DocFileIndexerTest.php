<?php

declare(strict_types=1);

use App\Ingestion\DocFileIndexer;
use App\Models\DocChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->path = useScratchDocs();
    config()->set('rag.chunking.min_tokens', 0);

    writeDocsPage('13.x', 'queues.md', docsPage('Queues', [
        'job-batching' => 'Job batching runs a group of jobs and fires a callback when they all finish.',
        'job-chaining' => 'Job chaining runs jobs one after another, stopping if one fails.',
    ]));
});

afterEach(fn () => File::deleteDirectory($this->path));

it('writes embeddings into the pgvector column and is idempotent', function () {
    $indexer = app(DocFileIndexer::class);

    expect($indexer->index('13.x', 'queues.md')->embedded)->toBe(2)
        ->and($indexer->index('13.x', 'queues.md')->unchanged)->toBe(2)
        ->and(DB::scalar("select vector_dims(embedding) from doc_chunks where anchor = 'job-batching'"))
        ->toBe(config('rag.embedding.dimensions'))
        ->and(DocChunk::where('anchor', 'job-batching')->sole()->embedding)->toHaveCount(config('rag.embedding.dimensions'));
});
