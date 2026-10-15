<?php

declare(strict_types=1);

use App\Contracts\Embedder;
use App\Models\DocChunk;
use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;
use Illuminate\Support\Facades\DB;

function storeChunk(string $version, string $anchor, string $content): DocChunk
{
    return DocChunk::factory()->version($version)->create([
        'anchor' => $anchor,
        'content' => $content,
        'embedding' => app(Embedder::class)->embedDocuments([$content])[0],
    ]);
}

beforeEach(function () {
    $this->retriever = app(Retriever::class);
});

it('ranks chunks by similarity to the question', function () {
    storeChunk('13.x', 'job-batching', 'Dispatch a batch of jobs with Bus batch and run a callback when the batch finishes');
    storeChunk('13.x', 'basic-routing', 'Define a route in routes web php using the Route facade');
    storeChunk('13.x', 'validation', 'Validate incoming request data with the validate method');

    $result = $this->retriever->retrieve('How do I dispatch a batch of jobs?', '13.x');

    expect($result->chunks[0]->chunk->anchor)->toBe('job-batching')
        ->and(array_map(fn (RetrievedChunk $chunk) => $chunk->rank, $result->chunks))->toBe([1, 2, 3])
        ->and($result->chunks[0]->similarity)->toBeGreaterThan($result->chunks[1]->similarity)
        ->and($result->bestSimilarity())->toBe($result->chunks[0]->similarity);
});

it('never returns chunks from another version', function () {
    storeChunk('13.x', 'job-batching', 'Dispatch a batch of jobs and run a callback when the batch finishes');
    storeChunk('12.x', 'job-batching', 'Job batches group jobs together');
    storeChunk('12.x', 'basic-routing', 'Define a route using the Route facade');

    $result = $this->retriever->retrieve('Dispatch a batch of jobs and run a callback when the batch finishes', '12.x');

    expect($result->chunks)->toHaveCount(2)
        ->and(array_unique(array_map(fn (RetrievedChunk $chunk) => $chunk->chunk->version, $result->chunks)))->toBe(['12.x']);
});

it('turns on hnsw iterative scans so version filtering cannot starve the results', function () {
    storeChunk('13.x', 'job-batching', 'Dispatch a batch of jobs');

    $this->retriever->retrieve('batch', '13.x');

    expect(DB::scalar('show hnsw.iterative_scan'))->toBe('strict_order');
});

it('limits results to top k', function () {
    foreach (range(1, 10) as $i) {
        storeChunk('13.x', "section-{$i}", "Queue workers process jobs {$i}");
    }

    expect($this->retriever->retrieve('queue workers', '13.x', topK: 4)->chunks)->toHaveCount(4);
});

it('does not load embeddings into the results', function () {
    storeChunk('13.x', 'job-batching', 'Dispatch a batch of jobs');

    $chunk = $this->retriever->retrieve('batch', '13.x')->chunks[0]->chunk;

    expect($chunk->getAttributes())->not->toHaveKey('embedding')
        ->and($chunk->url)->toContain('/docs/13.x/');
});

describe('hybrid mode', function () {
    it('finds an exact method name that vector search ranks low', function () {
        storeChunk('13.x', 'similarity', 'Use the whereVectorSimilarTo method to filter rows');
        foreach (range(1, 8) as $n) {
            storeChunk('13.x', "noise-{$n}", "Filter rows of the query builder by method {$n}");
        }

        $query = 'which method filters rows by whereVectorSimilarTo';
        $vector = $this->retriever->retrieve($query, '13.x', 'vector', topK: 9);
        $hybrid = $this->retriever->retrieve($query, '13.x', 'hybrid', topK: 3);
        $vectorRank = collect($vector->chunks)->search(fn (RetrievedChunk $hit) => $hit->chunk->anchor === 'similarity') + 1;

        expect($hybrid->mode)->toBe('hybrid')
            ->and($hybrid->chunks[0]->chunk->anchor)->toBe('similarity')
            ->and($hybrid->chunks[0]->keywordRank)->toBe(1)
            ->and($hybrid->chunks[0]->vectorRank)->toBe($vectorRank);
    });

    it('scores with rrf and keeps cosine similarity for the threshold', function () {
        storeChunk('13.x', 'job-batching', 'Dispatch a batch of jobs and run a callback when the batch finishes');
        storeChunk('13.x', 'basic-routing', 'Define a route using the Route facade');

        $result = $this->retriever->retrieve('dispatch a batch of jobs', '13.x', 'hybrid');
        $top = $result->chunks[0];

        expect($top->chunk->anchor)->toBe('job-batching')
            ->and($top->score)->toEqualWithDelta(1 / 61 + 1 / 61, 1e-9)
            ->and($top->similarity)->toEqualWithDelta(cosineSimilarity(
                app(Embedder::class)->embedQuery('dispatch a batch of jobs'),
                app(Embedder::class)->embedDocuments(['Dispatch a batch of jobs and run a callback when the batch finishes'])[0],
            ), 1e-4)
            ->and($result->bestSimilarity())->toBe($top->similarity);
    });

    it('keeps sections only one search found, with their similarity', function () {
        storeChunk('13.x', 'keyword-only', 'zebra');
        storeChunk('13.x', 'vector-only', 'Dispatch jobs');

        $result = $this->retriever->retrieve('zebra', '13.x', 'hybrid', topK: 1);

        expect($result->chunks[0]->chunk->anchor)->toBe('keyword-only')
            ->and($result->chunks[0]->keywordRank)->toBe(1)
            ->and($result->chunks[0]->similarity)->toBeFloat();
    });

    it('limits results to top k and never crosses versions', function () {
        storeChunk('13.x', 'job-batching', 'Dispatch a batch of jobs');
        storeChunk('13.x', 'job-chaining', 'Chain jobs to run in sequence');
        storeChunk('12.x', 'job-batching', 'Dispatch a batch of jobs');

        $result = $this->retriever->retrieve('batch of jobs', '13.x', 'hybrid', topK: 1);

        expect($result->chunks)->toHaveCount(1)
            ->and($result->chunks[0]->chunk->version)->toBe('13.x');
    });

    it('still answers when no query word is in the index', function () {
        storeChunk('13.x', 'job-batching', 'Dispatch a batch of jobs');

        expect($this->retriever->retrieve('the and of', '13.x', 'hybrid')->chunks)->toHaveCount(1);
    });
});
