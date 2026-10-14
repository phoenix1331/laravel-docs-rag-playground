<?php

declare(strict_types=1);

use App\Contracts\Embedder;
use App\Ingestion\DocFileIndexer;
use App\Models\DocChunk;
use App\Services\Embeddings\FakeEmbedder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->path = useScratchDocs();
    config()->set('rag.chunking.min_tokens', 0);

    $this->embedder = app(Embedder::class);
    $this->indexer = app(DocFileIndexer::class);

    writeDocsPage('13.x', 'queues.md', docsPage('Queues', [
        'introduction' => 'Queues let you defer slow work such as sending email to a background worker.',
        'job-batching' => 'Job batching runs a group of jobs and fires a callback when they all finish.',
        'job-chaining' => 'Job chaining runs jobs one after another, stopping if one fails.',
    ]));
});

afterEach(fn () => File::deleteDirectory($this->path));

it('embeds and stores every section on first run', function () {
    $result = $this->indexer->index('13.x', 'queues.md');

    expect($result->toArray())->toBe(['embedded' => 3, 'reused' => 0, 'unchanged' => 0, 'deleted' => 0])
        ->and(DocChunk::count())->toBe(3);

    $chunk = DocChunk::where('anchor', 'job-batching')->sole();
    expect($chunk->url)->toBe('https://laravel.com/docs/13.x/queues#job-batching')
        ->and($chunk->heading_path)->toBe('Queues > Job Batching')
        ->and($chunk->embedding_model)->toBe('fake')
        ->and($chunk->embedding)->toHaveCount(config('rag.embedding.dimensions'));
});

it('embeds nothing when run again on unchanged docs', function () {
    $this->indexer->index('13.x', 'queues.md');
    $calls = count($this->embedder->embedded);

    $result = $this->indexer->index('13.x', 'queues.md');

    expect($result->toArray())->toBe(['embedded' => 0, 'reused' => 0, 'unchanged' => 3, 'deleted' => 0])
        ->and($this->embedder->embedded)->toHaveCount($calls);
});

it('re-embeds only the section that changed', function () {
    $this->indexer->index('13.x', 'queues.md');
    $before = DocChunk::where('anchor', 'introduction')->sole()->updated_at;

    writeDocsPage('13.x', 'queues.md', docsPage('Queues', [
        'introduction' => 'Queues let you defer slow work such as sending email to a background worker.',
        'job-batching' => 'Job batching runs a group of jobs, then fires then, catch and finally callbacks.',
        'job-chaining' => 'Job chaining runs jobs one after another, stopping if one fails.',
    ]));
    $this->travel(1)->minute();

    $result = $this->indexer->index('13.x', 'queues.md');

    expect($result->embedded)->toBe(1)
        ->and($result->unchanged)->toBe(2)
        ->and(end($this->embedder->embedded))->toContain('then, catch and finally')
        ->and(DocChunk::where('anchor', 'job-batching')->sole()->content)->toContain('then, catch and finally')
        ->and(DocChunk::where('anchor', 'introduction')->sole()->updated_at)->toEqual($before);
});

it('deletes sections removed from the page', function () {
    $this->indexer->index('13.x', 'queues.md');

    writeDocsPage('13.x', 'queues.md', docsPage('Queues', [
        'introduction' => 'Queues let you defer slow work such as sending email to a background worker.',
        'job-batching' => 'Job batching runs a group of jobs and fires a callback when they all finish.',
    ]));

    $result = $this->indexer->index('13.x', 'queues.md');

    expect($result->deleted)->toBe(1)
        ->and(DocChunk::pluck('anchor')->sort()->values()->all())->toBe(['introduction', 'job-batching']);
});

it('reuses the stored vector when identical content moves to a new anchor', function () {
    $indexer = $this->indexer;
    writeDocsPage('13.x', 'routing.md', "# Routing\n\n<a name=\"a\"></a>\n## Redirects\n\nUse Route::redirect for redirects.\n");
    $indexer->index('13.x', 'routing.md');
    $calls = count($this->embedder->embedded);

    writeDocsPage('13.x', 'routing.md', "# Routing\n\n<a name=\"b\"></a>\n## Redirects\n\nUse Route::redirect for redirects.\n");
    $result = $indexer->index('13.x', 'routing.md');

    expect($result->toArray())->toBe(['embedded' => 0, 'reused' => 1, 'unchanged' => 0, 'deleted' => 1])
        ->and($this->embedder->embedded)->toHaveCount($calls)
        ->and(DocChunk::where('file', 'routing.md')->sole()->anchor)->toBe('b');
});

it('re-embeds chunks produced by a different embedding model', function () {
    $this->indexer->index('13.x', 'queues.md');
    DocChunk::query()->update(['embedding_model' => 'old-model']);

    $result = $this->indexer->index('13.x', 'queues.md');

    expect($result->embedded)->toBe(3)
        ->and(DocChunk::where('embedding_model', 'fake')->count())->toBe(3);
});

it('leaves other versions and files alone', function () {
    $other = DocChunk::factory()->version('12.x')->create(['file' => 'queues.md', 'anchor' => 'introduction']);
    $sibling = DocChunk::factory()->create(['file' => 'routing.md']);

    $this->indexer->index('13.x', 'queues.md');

    expect($other->fresh())->not->toBeNull()
        ->and($sibling->fresh())->not->toBeNull();
});

it('uses a fresh fake embedder per test', function () {
    expect($this->embedder)->toBeInstanceOf(FakeEmbedder::class);
});
