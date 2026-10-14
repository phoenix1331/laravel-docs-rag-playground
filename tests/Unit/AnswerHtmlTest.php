<?php

declare(strict_types=1);

use App\Answering\AnswerHtml;

it('renders markdown and links citations to their sources', function () {
    $html = AnswerHtml::render('Use **batches** [1][3].', 3);

    expect($html)->toContain('<strong>batches</strong>')
        ->toContain('<a href="#source-1" class="cite" aria-label="Source 1">1</a>')
        ->toContain('<a href="#source-3" class="cite" aria-label="Source 3">3</a>');
});

it('splits comma separated citations into separate chips', function () {
    expect(AnswerHtml::render('See [1, 2].', 2))->toContain('href="#source-1"')->toContain('href="#source-2"');
});

it('leaves brackets inside code alone', function () {
    $html = AnswerHtml::render("Use `\$jobs[1]` [1].\n\n```php\n\$items[2] = 'x';\n```", 2);

    expect($html)->toContain('<code>$jobs[1]</code>')
        ->toContain('$items[2]')
        ->and(substr_count($html, 'class="cite"'))->toBe(1);
});

it('does not link numbers for sections that do not exist', function () {
    expect(AnswerHtml::render('See [7].', 3))->toContain('[7]')->not->toContain('class="cite"');
});

it('escapes html from the model', function () {
    expect(AnswerHtml::render('<script>alert(1)</script> [1]', 1))->not->toContain('<script>');
});
