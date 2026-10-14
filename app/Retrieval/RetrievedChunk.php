<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Models\DocChunk;

final readonly class RetrievedChunk
{
    /**
     * @param  int  $rank  1-based position in the final ranking.
     * @param  float  $score  The value the ranking is ordered by (cosine similarity in vector mode).
     * @param  float|null  $similarity  Cosine similarity to the query, 1.0 being identical.
     */
    public function __construct(
        public DocChunk $chunk,
        public int $rank,
        public float $score,
        public ?float $similarity = null,
    ) {}
}
