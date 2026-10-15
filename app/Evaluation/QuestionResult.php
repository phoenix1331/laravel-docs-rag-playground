<?php

declare(strict_types=1);

namespace App\Evaluation;

final readonly class QuestionResult
{
    /**
     * @param  list<string>  $retrieved  Section keys ("page.md#anchor") in rank order.
     * @param  int|null  $firstRelevantRank  Null for a miss, and always null for unanswerable questions.
     */
    public function __construct(
        public EvalQuestion $question,
        public array $retrieved,
        public ?int $firstRelevantRank,
        public ?float $bestSimilarity,
    ) {}

    public function isHit(): bool
    {
        return $this->firstRelevantRank !== null;
    }

    /**
     * Whether the assistant would refuse without calling a model, because nothing reached the similarity threshold.
     */
    public function belowThreshold(float $threshold): bool
    {
        return $this->bestSimilarity === null || $this->bestSimilarity < $threshold;
    }
}
