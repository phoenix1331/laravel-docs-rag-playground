<?php

declare(strict_types=1);

namespace App\Retrieval;

final readonly class RetrievalResult
{
    /**
     * @param  list<RetrievedChunk>  $chunks
     */
    public function __construct(
        public string $query,
        public string $version,
        public string $mode,
        public array $chunks,
    ) {}

    /**
     * The highest cosine similarity among the results, used to decide whether the docs cover the question.
     */
    public function bestSimilarity(): ?float
    {
        $similarities = array_filter(array_map(fn (RetrievedChunk $chunk) => $chunk->similarity, $this->chunks), 'is_float');

        return $similarities === [] ? null : max($similarities);
    }

    public function isEmpty(): bool
    {
        return $this->chunks === [];
    }
}
