<?php

declare(strict_types=1);

namespace App\Evaluation;

final readonly class EvalReport
{
    /**
     * @param  list<QuestionResult>  $results
     */
    public function __construct(
        public string $version,
        public string $mode,
        public int $k,
        public float $threshold,
        public array $results,
    ) {}

    /**
     * @return list<QuestionResult>
     */
    public function answerable(): array
    {
        return array_values(array_filter($this->results, fn (QuestionResult $result) => ! $result->question->unanswerable));
    }

    /**
     * @return list<QuestionResult>
     */
    public function unanswerable(): array
    {
        return array_values(array_filter($this->results, fn (QuestionResult $result) => $result->question->unanswerable));
    }

    public function recallAtK(): float
    {
        return RetrievalMetrics::recallAtK($this->ranks(), $this->k);
    }

    public function meanReciprocalRank(): float
    {
        return RetrievalMetrics::meanReciprocalRank($this->ranks());
    }

    /**
     * Answerable questions with no expected section in the top k.
     *
     * @return list<QuestionResult>
     */
    public function misses(): array
    {
        return array_values(array_filter($this->answerable(), fn (QuestionResult $result) => ! $result->isHit()));
    }

    /**
     * Answerable questions the similarity threshold alone would refuse.
     *
     * @return list<QuestionResult>
     */
    public function wronglyBelowThreshold(): array
    {
        return array_values(array_filter($this->answerable(), fn (QuestionResult $result) => $result->belowThreshold($this->threshold)));
    }

    /**
     * @return list<int|null>
     */
    protected function ranks(): array
    {
        return array_map(fn (QuestionResult $result) => $result->firstRelevantRank, $this->answerable());
    }
}
