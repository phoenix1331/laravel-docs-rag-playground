<?php

declare(strict_types=1);

use App\Answering\DocsPrompt;

it('extracts cited section numbers in every bracket style', function () {
    expect(DocsPrompt::citedNumbers('See [2] and [1][3], also [4, 2].', 5))->toBe([1, 2, 3, 4]);
});

it('ignores numbers for sections that do not exist', function () {
    expect(DocsPrompt::citedNumbers('See [0], [2] and [9].', 3))->toBe([2]);
});

it('ignores markdown links and array syntax that are not citations', function () {
    expect(DocsPrompt::citedNumbers("Use \$items['key'] and [the docs](https://laravel.com).", 3))->toBe([]);
});

it('recognises the not covered reply with stray formatting', function (string $reply) {
    expect(DocsPrompt::isNotCovered($reply))->toBeTrue();
})->with(['NOT_COVERED', ' NOT_COVERED.', '`NOT_COVERED`', "**NOT_COVERED**\n"]);

it('does not treat an answer mentioning the phrase as a refusal', function () {
    expect(DocsPrompt::isNotCovered('Batching is covered [1]. NOT_COVERED parts: none.'))->toBeFalse();
});
