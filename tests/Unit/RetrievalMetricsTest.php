<?php

declare(strict_types=1);

use App\Evaluation\RetrievalMetrics;

it('finds the rank of the first expected section', function () {
    $retrieved = ['routing.md#basic-routing', 'queues.md#job-batching', 'queues.md#dispatching-batches'];

    expect(RetrievalMetrics::firstRelevantRank($retrieved, ['queues.md#dispatching-batches', 'queues.md#job-batching']))->toBe(2)
        ->and(RetrievalMetrics::firstRelevantRank($retrieved, ['cache.md#atomic-locks']))->toBeNull()
        ->and(RetrievalMetrics::firstRelevantRank([], ['cache.md#atomic-locks']))->toBeNull();
});

it('counts a question towards recall@k only when its first hit is within k', function () {
    $ranks = [1, 3, null, 5];

    expect(RetrievalMetrics::recallAtK($ranks, 5))->toBe(0.75)
        ->and(RetrievalMetrics::recallAtK($ranks, 3))->toBe(0.5)
        ->and(RetrievalMetrics::recallAtK($ranks, 1))->toBe(0.25);
});

it('averages the reciprocal rank and scores a miss as zero', function () {
    // (1 + 1/2 + 0 + 1/4) / 4
    expect(RetrievalMetrics::meanReciprocalRank([1, 2, null, 4]))->toEqualWithDelta(0.4375, 1e-9)
        ->and(RetrievalMetrics::meanReciprocalRank([1, 1]))->toBe(1.0)
        ->and(RetrievalMetrics::meanReciprocalRank([null, null]))->toBe(0.0);
});

it('scores an empty set as zero rather than dividing by zero', function () {
    expect(RetrievalMetrics::recallAtK([], 5))->toBe(0.0)
        ->and(RetrievalMetrics::meanReciprocalRank([]))->toBe(0.0);
});
