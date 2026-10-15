<?php

declare(strict_types=1);

namespace App\Evaluation;

/**
 * Ranking metrics over the 1-based rank of the first relevant result per question, null meaning no relevant
 * result was retrieved. Each question has one or more acceptable sections and any one of them is a hit, so
 * recall@k here is the share of questions with a hit in the top k (often called hit rate).
 */
final class RetrievalMetrics
{
    /**
     * The 1-based position of the first retrieved section that is one of the expected sections.
     *
     * @param  list<string>  $retrieved  Section keys in rank order.
     * @param  list<string>  $expected
     */
    public static function firstRelevantRank(array $retrieved, array $expected): ?int
    {
        foreach ($retrieved as $index => $section) {
            if (in_array($section, $expected, true)) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * Share of questions whose first relevant result is within the top k.
     *
     * @param  list<int|null>  $ranks
     */
    public static function recallAtK(array $ranks, int $k): float
    {
        if ($ranks === []) {
            return 0.0;
        }

        $hits = array_filter($ranks, fn (?int $rank) => $rank !== null && $rank <= $k);

        return count($hits) / count($ranks);
    }

    /**
     * Mean of 1 / rank of the first relevant result, counting a miss as 0. Rewards putting the right section first.
     *
     * @param  list<int|null>  $ranks
     */
    public static function meanReciprocalRank(array $ranks): float
    {
        if ($ranks === []) {
            return 0.0;
        }

        return array_sum(array_map(fn (?int $rank) => $rank === null ? 0.0 : 1 / $rank, $ranks)) / count($ranks);
    }
}
