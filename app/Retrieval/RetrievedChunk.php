<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Models\DocChunk;

final readonly class RetrievedChunk
{
    /**
     * @param  int  $rank  1-based position in the final ranking.
     * @param  float  $score  The value the ranking is ordered by: cosine similarity in vector mode, the RRF score in hybrid mode.
     * @param  float|null  $similarity  Cosine similarity to the query, 1.0 being identical.
     * @param  int|null  $vectorRank  Hybrid mode: position in the vector ranking, null if vector search did not return it.
     * @param  int|null  $keywordRank  Hybrid mode: position in the full-text ranking, null if no query word matched.
     */
    public function __construct(
        public DocChunk $chunk,
        public int $rank,
        public float $score,
        public ?float $similarity = null,
        public ?int $vectorRank = null,
        public ?int $keywordRank = null,
    ) {}
}
