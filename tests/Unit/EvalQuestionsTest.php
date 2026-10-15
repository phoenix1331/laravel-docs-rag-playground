<?php

declare(strict_types=1);

$questions = json_decode(file_get_contents(__DIR__.'/../../database/eval/questions.json'), true, flags: JSON_THROW_ON_ERROR)['questions'];

it('has unique ids', function () use ($questions) {
    $ids = array_column($questions, 'id');

    expect($ids)->toHaveCount(count(array_unique($ids)));
});

it('gives every question a version, text and expected sections', function () use ($questions) {
    foreach ($questions as $question) {
        expect($question)->toHaveKeys(['id', 'version', 'question', 'expected'])
            ->and($question['question'])->not->toBeEmpty();

        $unanswerable = $question['unanswerable'] ?? false;
        expect($question['expected'] === [])->toBe($unanswerable, "{$question['id']}: unanswerable questions, and only those, have no expected sections");

        foreach ($question['expected'] as $expected) {
            expect($expected)->toMatch('/^[a-z0-9-]+\.md#[a-z0-9-]+$/');
        }
    }
});

it('includes questions the docs cannot answer', function () use ($questions) {
    expect(array_filter($questions, fn (array $question) => $question['unanswerable'] ?? false))->not->toBeEmpty();
});
