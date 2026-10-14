<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Contracts\Embedder;
use App\Models\DocChunk;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Retriever
{
    public const array MODES = ['vector'];

    protected bool $iterativeScanEnabled = false;

    public function __construct(
        protected Embedder $embedder,
        protected string $defaultMode = 'vector',
        protected int $defaultTopK = 6,
    ) {}

    /**
     * Find the chunks of one docs version most relevant to the query.
     *
     * The version filter is part of the SQL query itself, so chunks from another version can never be returned.
     */
    public function retrieve(string $query, string $version, ?string $mode = null, ?int $topK = null): RetrievalResult
    {
        $mode ??= $this->defaultMode;
        $topK ??= $this->defaultTopK;

        $chunks = match ($mode) {
            'vector' => $this->vector($query, $version, $topK),
            default => throw new InvalidArgumentException("Unknown retrieval mode [{$mode}]. Use one of: ".implode(', ', self::MODES).'.'),
        };

        return new RetrievalResult($query, $version, $mode, $chunks);
    }

    /**
     * Rank chunks by cosine similarity between their embedding and the query's embedding.
     *
     * @return list<RetrievedChunk>
     */
    protected function vector(string $query, string $version, int $topK): array
    {
        $this->enableIterativeScan();

        $embedding = $this->embedder->embedQuery($query);

        $rows = DocChunk::forVersion($version)
            ->select(['id', 'version', 'file', 'heading', 'heading_path', 'anchor', 'url', 'content', 'token_count'])
            ->selectVectorDistance('embedding', $embedding, as: 'distance')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.0)
            ->limit($topK)
            ->get();

        return $rows->values()->map(function (DocChunk $chunk, int $index) {
            $similarity = 1 - (float) $chunk->getAttribute('distance');

            return new RetrievedChunk($chunk, rank: $index + 1, score: $similarity, similarity: $similarity);
        })->all();
    }

    /**
     * The HNSW index gathers a fixed number of nearest candidates before the version filter is applied,
     * which can leave fewer than k results once several versions are stored. Iterative scans (pgvector
     * 0.8+) keep searching the index until enough rows pass the filter.
     */
    protected function enableIterativeScan(): void
    {
        if ($this->iterativeScanEnabled || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('set hnsw.iterative_scan = strict_order');
        $this->iterativeScanEnabled = true;
    }
}
