<?php

declare(strict_types=1);

use App\Models\DocChunk;
use Illuminate\Support\Facades\DB;

it('sizes the vector column from config', function () {
    $type = DB::scalar(<<<'SQL'
        select format_type(atttypid, atttypmod)
        from pg_attribute
        where attrelid = 'doc_chunks'::regclass and attname = 'embedding'
    SQL);

    expect($type)->toBe('vector('.config('rag.embedding.dimensions').')');
});

it('indexes the embedding with hnsw cosine ops', function () {
    $definition = DB::scalar("select indexdef from pg_indexes where tablename = 'doc_chunks' and indexdef like '%hnsw%'");

    expect($definition)->toContain('vector_cosine_ops');
});

it('round trips an embedding through pgvector', function () {
    $chunk = DocChunk::factory()->create();

    expect($chunk->fresh()->embedding)
        ->toHaveCount(config('rag.embedding.dimensions'))
        ->and($chunk->fresh()->embedding[0])->toEqualWithDelta($chunk->embedding[0], 0.0001);
});

it('keeps a generated tsvector of the heading path and content', function () {
    $chunk = DocChunk::factory()->create([
        'heading_path' => 'Eloquent > Deleting Models > Soft Deleting',
        'content' => '### Soft Deleting'."\n\n".'Records are not removed; a deleted_at timestamp is set instead.',
    ]);

    $vector = fn () => DB::scalar('select search_vector::text from doc_chunks where id = ?', [$chunk->id]);

    expect($vector())->toContain("'eloqu':1A")
        ->and($vector())->toContain("'timestamp'");

    $chunk->update(['content' => 'Queued listeners run on a worker.']);

    expect($vector())->toContain("'worker'")
        ->and($vector())->not->toContain("'timestamp'");
});

it('indexes the search vector with gin', function () {
    $definition = DB::scalar("select indexdef from pg_indexes where indexname = 'doc_chunks_search_vector_index'");

    expect($definition)->toContain('USING gin (search_vector)');
});

it('matches stemmed words and ranks heading matches above body matches', function () {
    DocChunk::factory()->create(['anchor' => 'body', 'heading_path' => 'Queues > Job Middleware', 'content' => 'A job may be deleted after it fails.']);
    DocChunk::factory()->create(['anchor' => 'heading', 'heading_path' => 'Eloquent > Soft Deleting', 'content' => 'Records stay in the table.']);
    DocChunk::factory()->create(['anchor' => 'unrelated', 'heading_path' => 'Routing > Basic Routing', 'content' => 'Define a route.']);

    $config = DocChunk::TEXT_SEARCH_CONFIG;
    $anchors = DB::table('doc_chunks')
        ->whereRaw("search_vector @@ websearch_to_tsquery('{$config}', ?)", ['delete'])
        ->orderByRaw("ts_rank(search_vector, websearch_to_tsquery('{$config}', ?)) desc", ['delete'])
        ->pluck('anchor')
        ->all();

    expect($anchors)->toBe(['heading', 'body']);
});

it('keeps the search vector out of serialised models', function () {
    expect(DocChunk::factory()->create()->fresh()->toArray())->not->toHaveKey('search_vector');
});
