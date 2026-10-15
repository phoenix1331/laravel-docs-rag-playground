<?php

declare(strict_types=1);

use App\Retrieval\ReciprocalRankFusion;

it('scores each item as the sum of 1 / (k + rank) across lists', function () {
    $scores = ReciprocalRankFusion::fuse([['a', 'b', 'c'], ['b', 'c', 'd']], k: 60);

    expect($scores['b'])->toEqualWithDelta(1 / 62 + 1 / 61, 1e-12)
        ->and($scores['c'])->toEqualWithDelta(1 / 63 + 1 / 62, 1e-12)
        ->and($scores['a'])->toEqualWithDelta(1 / 61, 1e-12)
        ->and($scores['d'])->toEqualWithDelta(1 / 63, 1e-12);
});

it('ranks items found by both lists above items found by one', function () {
    expect(array_keys(ReciprocalRankFusion::fuse([['a', 'b', 'c'], ['b', 'c', 'd']])))
        ->toBe(['b', 'c', 'a', 'd']);
});

it('keeps first-seen order for ties', function () {
    expect(array_keys(ReciprocalRankFusion::fuse([['a', 'b'], ['b', 'a']])))->toBe(['a', 'b']);
});

it('lets a smaller k reward first places more strongly', function () {
    $rankings = [['top', 'both'], ['other', 'both']];

    expect(array_key_first(ReciprocalRankFusion::fuse($rankings, k: 60)))->toBe('both')
        ->and(array_key_first(ReciprocalRankFusion::fuse($rankings, k: 0)))->toBe('top');
});

it('handles empty and one-sided rankings', function () {
    expect(ReciprocalRankFusion::fuse([]))->toBe([])
        ->and(array_keys(ReciprocalRankFusion::fuse([[], [3, 1]])))->toBe([3, 1])
        ->and(array_keys(ReciprocalRankFusion::fuse([[1, 2], []])))->toBe([1, 2]);
});
