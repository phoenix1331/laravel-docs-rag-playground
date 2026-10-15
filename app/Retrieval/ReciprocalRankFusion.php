<?php

declare(strict_types=1);

namespace App\Retrieval;

/**
 * Merges several rankings of the same items into one, using only each item's position in each list.
 *
 * Vector similarity and full-text rank are on unrelated scales, so their scores cannot be added. RRF
 * scores each item as the sum of 1 / (k + rank) over the lists it appears in: items high in several
 * lists win, and an item found by only one list still counts. k (60 by convention) damps the lead of
 * a first place so no single list dominates.
 */
final class ReciprocalRankFusion
{
    /**
     * @param  list<list<int|string>>  $rankings  Item ids, best first, one list per ranking.
     * @return array<int|string, float> Fused scores keyed by id, best first. Ties keep first-seen order.
     */
    public static function fuse(array $rankings, int $k = 60): array
    {
        $scores = [];

        foreach ($rankings as $ranking) {
            foreach (array_values($ranking) as $index => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1 / ($k + $index + 1);
            }
        }

        // arsort is stable, so equal scores stay in the order they were first seen.
        arsort($scores);

        return $scores;
    }
}
