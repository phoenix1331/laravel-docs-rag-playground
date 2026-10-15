<?php

declare(strict_types=1);

namespace App\Chunking;

/**
 * Splits a Laravel docs Markdown page into sections on ## and ### headings.
 *
 * Framework-free so it can be unit tested against fixture Markdown.
 */
final class MarkdownChunker
{
    private const string FENCE = '/^\s*(`{3,}|~{3,})/';

    private const string HEADING = '/^(#{2,3})\s+(.+?)\s*#*\s*$/';

    private const string ANCHOR = '/^\s*<a name="([^"]+)"><\/a>\s*$/';

    private const string WRAPPER = '/^\s*<\/?div\b[^>]*>\s*$/';

    private const string LINK_INDEX = '/^\s*(?:[-*]\s+)?(?:\[[^\]]*\]\(#[^)]*\)\s*)+$/';

    private const string DATA_IMAGE = '/!\[([^\]]*)\]\(data:[^)]*\)/';

    /**
     * Bump when chunking rules change, so unchanged pages are re-chunked on the next ingest.
     */
    public const int RULES_VERSION = 1;

    public function __construct(
        private readonly int $maxTokens = 800,
        private readonly int $minTokens = 60,
    ) {}

    /**
     * @return list<Chunk>
     */
    public function chunk(string $markdown): array
    {
        $chunks = [];
        $seen = [];

        foreach ($this->mergeShortSections($this->sections($markdown)) as $section) {
            $section['anchor'] = $this->uniqueAnchor($section['anchor'], $seen);

            array_push($chunks, ...$this->split($section));
        }

        return $chunks;
    }

    /**
     * Identifies the chunking rules and limits; pages chunked under a different signature are re-processed.
     */
    public function signature(): string
    {
        return implode(':', [self::RULES_VERSION, $this->maxTokens, $this->minTokens]);
    }

    /**
     * Estimate tokens at roughly four characters each.
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Walk the page line by line, collecting sections and skipping the title and table of contents.
     *
     * @return list<array{level: int, heading: string, anchor: string, path: list<string>, parent: ?string, body: string}>
     */
    private function sections(string $markdown): array
    {
        $sections = [];
        $current = null;
        $title = null;
        $parent = null;
        $pendingAnchor = null;
        $fence = null;

        // Not \R: without the u flag it also matches a lone 0x85 byte, splitting characters such as ✅ (e2 9c 85).
        foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
            if ($fence !== null) {
                $current['lines'][] = $line;
                $fence = $this->closesFence($line, $fence) ? null : $fence;

                continue;
            }

            if (preg_match(self::FENCE, $line, $match)) {
                $fence = $match[1];
                $current['lines'][] = $line;

                continue;
            }

            if (preg_match(self::ANCHOR, $line, $match)) {
                $pendingAnchor = $match[1];

                continue;
            }

            if ($title === null && $current === null && preg_match('/^#\s+(.+)$/', $line, $match)) {
                $title = $this->cleanHeading($match[1]);

                continue;
            }

            if (preg_match(self::HEADING, $line, $match)) {
                $sections[] = $current;

                $level = strlen($match[1]);
                $heading = $this->cleanHeading($match[2]);
                $parent = $level === 2 ? $heading : $parent;

                $current = [
                    'level' => $level,
                    'heading' => $heading,
                    'anchor' => $pendingAnchor ?? $this->slug($heading),
                    'path' => array_values(array_filter([$title, $level === 3 ? $parent : null, $heading])),
                    'parent' => $parent,
                    'lines' => [],
                ];
                $pendingAnchor = null;

                continue;
            }

            if ($current === null || preg_match(self::WRAPPER, $line) || preg_match(self::LINK_INDEX, $line)) {
                continue;
            }

            $current['lines'][] = preg_replace(self::DATA_IMAGE, '[Image: $1]', $line);
        }

        $sections[] = $current;

        return array_values(array_filter(array_map(function (?array $section) {
            if ($section === null) {
                return null;
            }

            $section['body'] = trim(implode("\n", $section['lines']));
            unset($section['lines']);

            return $section['body'] === '' ? null : $section;
        }, $sections)));
    }

    /**
     * Fold sections under the minimum size into a neighbour under the same ## parent, as long as the result fits.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function mergeShortSections(array $sections): array
    {
        $merged = [];

        for ($i = 0; $i < count($sections); $i++) {
            $section = $sections[$i];

            if (! $this->isShort($section)) {
                $merged[] = $section;

                continue;
            }

            $previous = array_key_last($merged);

            if ($previous !== null && $this->canMerge($merged[$previous], $section)) {
                $merged[$previous] = $this->absorb($merged[$previous], $section);

                continue;
            }

            $next = $sections[$i + 1] ?? null;

            if ($next !== null && $this->canMerge($section, $next)) {
                $section = $this->absorb($section, $next);
                $i++;
            }

            $merged[] = $section;
        }

        return $merged;
    }

    /**
     * Split a section over the token limit into parts on paragraph boundaries, each prefixed with the heading.
     *
     * @param  array<string, mixed>  $section
     * @return list<Chunk>
     */
    private function split(array $section): array
    {
        $prefix = $this->headingLine($section);
        $budget = $this->maxTokens - self::estimateTokens($prefix."\n\n");
        $parts = [];
        $part = [];

        foreach ($this->fitParagraphs($this->paragraphs($section['body']), $budget) as $paragraph) {
            $candidate = implode("\n\n", [...$part, $paragraph]);

            if ($part !== [] && self::estimateTokens($candidate) > $budget) {
                $parts[] = $part;
                $part = [];
            }

            $part[] = $paragraph;
        }

        $parts[] = $part;

        return array_map(fn (array $paragraphs, int $index) => $this->makeChunk(
            $section,
            $index === 0 ? $section['anchor'] : "{$section['anchor']}-part-".($index + 1),
            $prefix."\n\n".implode("\n\n", $paragraphs),
        ), $parts, array_keys($parts));
    }

    /**
     * Break text into paragraphs on blank lines, treating each fenced code block as a single paragraph.
     *
     * @return list<string>
     */
    private function paragraphs(string $body): array
    {
        $paragraphs = [];
        $buffer = [];
        $fence = null;

        foreach (explode("\n", $body) as $line) {
            if ($fence !== null) {
                $buffer[] = $line;
                $fence = $this->closesFence($line, $fence) ? null : $fence;

                continue;
            }

            if (preg_match(self::FENCE, $line, $match)) {
                $fence = $match[1];
            }

            if ($fence === null && trim($line) === '') {
                $paragraphs[] = implode("\n", $buffer);
                $buffer = [];

                continue;
            }

            $buffer[] = $line;
        }

        $paragraphs[] = implode("\n", $buffer);

        return array_values(array_filter($paragraphs, fn (string $paragraph) => trim($paragraph) !== ''));
    }

    /**
     * Break any table larger than the budget into row groups; prose and code blocks are left whole.
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function fitParagraphs(array $paragraphs, int $budget): array
    {
        $fitted = [];

        foreach ($paragraphs as $paragraph) {
            if (self::estimateTokens($paragraph) <= $budget || ! $this->isTable($paragraph)) {
                $fitted[] = $paragraph;

                continue;
            }

            // Keep a short lead-in such as "Below you will find..." with the first rows it introduces.
            $lead = end($fitted);
            $lead = $lead !== false && ! $this->isTable($lead) && self::estimateTokens($lead) <= intdiv($budget, 3)
                ? array_pop($fitted)
                : null;

            array_push($fitted, ...$this->splitTable($paragraph, $budget, $lead));
        }

        return $fitted;
    }

    private function isTable(string $paragraph): bool
    {
        $lines = explode("\n", $paragraph);

        return count($lines) > 2
            && str_starts_with(ltrim($lines[0]), '|')
            && preg_match('/^\s*\|?\s*:?-{3,}/', $lines[1]) === 1;
    }

    /**
     * Group table rows into pieces within the budget, repeating the header row and divider in each.
     *
     * @return list<string>
     */
    private function splitTable(string $table, int $budget, ?string $lead = null): array
    {
        $lines = explode("\n", $table);
        $header = $lines[0]."\n".$lines[1];
        $prefix = $lead === null ? $header : $lead."\n\n".$header;
        $pieces = [];
        $rows = [];

        foreach (array_slice($lines, 2) as $row) {
            if ($rows !== [] && self::estimateTokens($prefix."\n".implode("\n", [...$rows, $row])) > $budget) {
                $pieces[] = $prefix."\n".implode("\n", $rows);
                $prefix = $header;
                $rows = [];
            }

            $rows[] = $row;
        }

        $pieces[] = $prefix."\n".implode("\n", $rows);

        return $pieces;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function makeChunk(array $section, string $anchor, string $content): Chunk
    {
        $headingPath = implode(' > ', $section['path']);

        return new Chunk(
            heading: $section['heading'],
            headingPath: $headingPath,
            anchor: $anchor,
            sectionAnchor: $section['anchor'],
            content: $content,
            tokenCount: self::estimateTokens($content),
            hash: hash('sha256', $headingPath."\n\n".$content),
        );
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function isShort(array $section): bool
    {
        return self::estimateTokens($section['body']) < $this->minTokens;
    }

    /**
     * @param  array<string, mixed>  $into
     * @param  array<string, mixed>  $from
     */
    private function canMerge(array $into, array $from): bool
    {
        return $into['parent'] === $from['parent']
            && self::estimateTokens($this->headingLine($into)."\n\n".$this->absorb($into, $from)['body']) <= $this->maxTokens;
    }

    /**
     * Append a section to another, keeping its heading so the merged text still reads correctly.
     *
     * @param  array<string, mixed>  $into
     * @param  array<string, mixed>  $from
     * @return array<string, mixed>
     */
    private function absorb(array $into, array $from): array
    {
        $into['body'] .= "\n\n".$this->headingLine($from)."\n\n".$from['body'];

        return $into;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function headingLine(array $section): string
    {
        return str_repeat('#', $section['level']).' '.$section['heading'];
    }

    private function closesFence(string $line, string $fence): bool
    {
        return preg_match('/^\s*('.preg_quote($fence[0], '/').'{'.strlen($fence).',})\s*$/', $line) === 1;
    }

    private function cleanHeading(string $heading): string
    {
        $heading = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $heading);

        return trim(str_replace('`', '', $heading));
    }

    private function slug(string $heading): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($heading)), '-');
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function uniqueAnchor(string $anchor, array &$seen): string
    {
        $unique = $anchor;

        for ($suffix = 2; isset($seen[$unique]); $suffix++) {
            $unique = "{$anchor}-{$suffix}";
        }

        $seen[$unique] = true;

        return $unique;
    }
}
