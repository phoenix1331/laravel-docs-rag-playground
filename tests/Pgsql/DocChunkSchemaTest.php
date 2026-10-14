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
