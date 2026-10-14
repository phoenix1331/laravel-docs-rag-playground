<?php

declare(strict_types=1);

namespace App\Answering;

use Illuminate\Support\Str;

/**
 * Renders a model answer as safe HTML and turns [n] citations into links to the matching source.
 */
final class AnswerHtml
{
    public static function render(string $markdown, int $sectionCount): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        // Leave code untouched: $items[1] in a snippet is not a citation.
        $parts = preg_split('#(<pre\b.*?</pre>|<code\b.*?</code>)#s', $html, flags: PREG_SPLIT_DELIM_CAPTURE);

        return implode('', array_map(
            fn (string $part) => str_starts_with($part, '<pre') || str_starts_with($part, '<code')
                ? $part
                : self::linkCitations($part, $sectionCount),
            $parts,
        ));
    }

    private static function linkCitations(string $html, int $sectionCount): string
    {
        return preg_replace_callback('/\[(\d+(?:\s*,\s*\d+)*)\]/', function (array $match) use ($sectionCount) {
            $numbers = array_map('intval', preg_split('/\s*,\s*/', $match[1]));

            if (array_filter($numbers, fn (int $n) => $n < 1 || $n > $sectionCount) !== []) {
                return $match[0];
            }

            return implode('', array_map(
                fn (int $n) => sprintf('<a href="#source-%1$d" class="cite" aria-label="Source %1$d">%1$d</a>', $n),
                $numbers,
            ));
        }, $html);
    }
}
