<?php

declare(strict_types=1);

use App\Models\DocChunk;
use Illuminate\Database\UniqueConstraintViolationException;

it('stores and reads back the embedding as a list of floats', function () {
    $chunk = DocChunk::factory()->create();

    $embedding = $chunk->fresh()->embedding;

    expect($embedding)->toBeArray()
        ->toHaveCount(config('rag.embedding.dimensions'))
        ->and($embedding[0])->toBeFloat();
});

it('scopes chunks to a single docs version', function () {
    DocChunk::factory()->count(2)->create();
    DocChunk::factory()->version('12.x')->create();

    expect(DocChunk::forVersion('13.x')->count())->toBe(2)
        ->and(DocChunk::forVersion('12.x')->sole()->url)->toContain('/docs/12.x/');
});

it('rejects a duplicate anchor within the same version and file', function () {
    $chunk = DocChunk::factory()->create();

    DocChunk::factory()->create($chunk->only('version', 'file', 'anchor'));
})->throws(UniqueConstraintViolationException::class);
