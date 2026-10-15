<?php

declare(strict_types=1);

use App\Chunking\Chunk;
use App\Chunking\MarkdownChunker;

function markdownFixture(string $name): string
{
    return file_get_contents(__DIR__."/../../Fixtures/markdown/{$name}.md");
}

/**
 * @return array<string, Chunk>
 */
function chunksByAnchor(string $fixture, int $maxTokens = 800, int $minTokens = 0): array
{
    $chunks = (new MarkdownChunker($maxTokens, $minTokens))->chunk(markdownFixture($fixture));

    return array_combine(array_map(fn (Chunk $chunk) => $chunk->anchor, $chunks), $chunks);
}

describe('headings', function () {
    it('splits on ## and ### headings', function () {
        expect(array_keys(chunksByAnchor('nested-headings')))
            ->toBe(['introduction', 'driver-prerequisites', 'job-batching', 'dispatching-batches']);
    });

    it('builds the heading path from the page title and parent heading', function () {
        $chunks = chunksByAnchor('nested-headings');

        expect($chunks['introduction']->headingPath)->toBe('Queues > Introduction')
            ->and($chunks['driver-prerequisites']->headingPath)->toBe('Queues > Introduction > Driver Notes and Prerequisites')
            ->and($chunks['dispatching-batches']->headingPath)->toBe('Queues > Job Batching > Dispatching Batches');
    });

    it('uses the explicit anchor rather than a slug of the heading', function () {
        expect(chunksByAnchor('nested-headings')['driver-prerequisites']->heading)->toBe('Driver Notes and Prerequisites');
    });

    it('falls back to a slug when a heading has no anchor', function () {
        $chunks = (new MarkdownChunker(800, 0))->chunk("# Page\n\n## Rate Limiting & `Throttle`\n\nSome text about limits.");

        expect($chunks[0]->anchor)->toBe('rate-limiting-throttle')
            ->and($chunks[0]->heading)->toBe('Rate Limiting & Throttle');
    });

    it('keeps #### headings inside their section', function () {
        expect(chunksByAnchor('nested-headings')['driver-prerequisites']->content)
            ->toContain('#### Redis')
            ->toContain('configure a Redis database connection');
    });

    it('skips the title and table of contents', function () {
        $content = implode("\n", array_map(fn (Chunk $chunk) => $chunk->content, chunksByAnchor('nested-headings')));

        expect($content)->not->toContain('](#')
            ->not->toContain('# Queues');
    });

    it('strips anchor tags and wrapper divs from content', function () {
        $content = implode("\n", array_map(fn (Chunk $chunk) => $chunk->content, chunksByAnchor('nested-headings')));

        expect($content)->not->toContain('<a name=')
            ->not->toContain('<div')
            ->not->toContain('</div>');
    });

    it('starts each chunk with its heading', function () {
        expect(chunksByAnchor('nested-headings')['dispatching-batches']->content)
            ->toStartWith("### Dispatching Batches\n\nTo dispatch a batch of jobs");
    });
});

describe('code blocks', function () {
    it('ignores # characters inside fenced code blocks', function () {
        expect(array_keys(chunksByAnchor('code-blocks')))->toBe(['server-requirements', 'optimization']);
    });

    it('keeps code block lines in the section content', function () {
        $chunks = chunksByAnchor('code-blocks');

        expect($chunks['server-requirements']->content)
            ->toContain('# Install the required PHP extensions')
            ->toContain('## This line is a comment, not a heading')
            ->toContain('assume a Debian based distribution')
            ->and($chunks['optimization']->content)
            ->toContain('### Not a heading either')
            ->toContain('clear the cache');
    });
});

describe('long sections', function () {
    it('splits oversized sections on paragraph boundaries', function () {
        $chunks = array_values(chunksByAnchor('long-section', maxTokens: 150));

        expect($chunks)->toHaveCount(3)
            ->and($chunks[0]->content)->toContain('paragraph one')->toContain('paragraph two')
            ->and($chunks[2]->content)->toContain('paragraph three')->toContain('paragraph four');
    });

    it('prefixes every part with the heading', function () {
        foreach (chunksByAnchor('long-section', maxTokens: 150) as $chunk) {
            expect($chunk->content)->toStartWith('## Running the Queue Worker')
                ->and($chunk->heading)->toBe('Running the Queue Worker');
        }
    });

    it('gives each part a unique anchor that links to the same section', function () {
        $chunks = chunksByAnchor('long-section', maxTokens: 150);

        expect(array_keys($chunks))->toBe(['running-the-queue-worker', 'running-the-queue-worker-part-2', 'running-the-queue-worker-part-3']);

        foreach ($chunks as $chunk) {
            expect($chunk->sectionAnchor)->toBe('running-the-queue-worker');
        }
    });

    it('never splits a code block, even across blank lines', function () {
        $code = array_values(chunksByAnchor('long-section', maxTokens: 150))[1]->content;

        expect(substr_count($code, '```'))->toBe(2)
            ->and($code)->toContain('ImportCsv(1, 100)')
            ->toContain('A blank line inside the block must not split it');
    });

    it('keeps parts within the token limit unless a single block is larger', function () {
        $chunks = array_values(chunksByAnchor('long-section', maxTokens: 150));

        expect($chunks[0]->tokenCount)->toBeLessThanOrEqual(150)
            ->and($chunks[2]->tokenCount)->toBeLessThanOrEqual(150);
    });
});

describe('short sections', function () {
    it('merges short sections into a neighbour under the same parent', function () {
        expect(array_keys(chunksByAnchor('short-sections', maxTokens: 120, minTokens: 25)))
            ->toBe(['basic-routing', 'route-parameters', 'fallback-routes']);
    });

    it('lets a short parent intro absorb its first child', function () {
        expect(chunksByAnchor('short-sections', maxTokens: 120, minTokens: 25)['basic-routing']->content)
            ->toStartWith("## Basic Routing\n\nShort intro.")
            ->toContain('### The Default Route Files')
            ->toContain('### Redirect Routes');
    });

    it('merges a short child into the section before it', function () {
        expect(chunksByAnchor('short-sections', maxTokens: 120, minTokens: 25)['route-parameters']->content)
            ->toContain("### Optional Parameters\n\nAdd a question mark.");
    });

    it('never merges across top-level sections', function () {
        expect(chunksByAnchor('short-sections', maxTokens: 120, minTokens: 25)['fallback-routes']->content)
            ->toBe("## Fallback Routes\n\nUse Route::fallback.");
    });

    it('does not merge when the result would exceed the token limit', function () {
        expect(chunksByAnchor('short-sections', maxTokens: 40, minTokens: 25))->toHaveKey('redirect-routes');
    });
});

describe('chunk metadata', function () {
    it('estimates tokens at roughly four characters each', function () {
        $chunk = chunksByAnchor('code-blocks')['optimization'];

        expect($chunk->tokenCount)->toBe((int) ceil(mb_strlen($chunk->content) / 4));
    });

    it('hashes the heading path and content', function () {
        $chunk = chunksByAnchor('code-blocks')['optimization'];

        expect($chunk->hash)->toBe(hash('sha256', $chunk->embeddingText()))
            ->and($chunk->embeddingText())->toStartWith("Deployment > Optimization\n\n## Optimization");
    });

    it('is deterministic and changes the hash when content changes', function () {
        $chunker = new MarkdownChunker(800, 0);
        $markdown = markdownFixture('code-blocks');

        $first = $chunker->chunk($markdown);
        $edited = $chunker->chunk(str_replace('Debian based', 'Debian-based', $markdown));

        expect($chunker->chunk($markdown))->toEqual($first)
            ->and($edited[0]->hash)->not->toBe($first[0]->hash)
            ->and($edited[1]->hash)->toBe($first[1]->hash);
    });

    it('makes duplicate anchors within a file unique', function () {
        $chunks = (new MarkdownChunker(800, 0))->chunk("# Page\n\n## Examples\n\nFirst examples.\n\n## Examples\n\nSecond examples.");

        expect(array_map(fn (Chunk $chunk) => $chunk->anchor, $chunks))->toBe(['examples', 'examples-2']);
    });

    it('returns nothing for a page without sections', function () {
        expect((new MarkdownChunker)->chunk("# Title\n\n- [Link](#link)"))->toBe([]);
    });
});

describe('oversized blocks', function () {
    it('splits large tables on row boundaries and repeats the header', function () {
        $parts = array_values(array_filter(
            chunksByAnchor('oversized-blocks', maxTokens: 300),
            fn (Chunk $chunk) => $chunk->sectionAnchor === 'facade-class-reference',
        ));

        expect(count($parts))->toBeGreaterThan(1);

        foreach ($parts as $part) {
            expect($part->content)->toContain("| Facade | Class | Service Container Binding |\n| --- | --- | --- |")
                ->and($part->tokenCount)->toBeLessThanOrEqual(300);
        }

        $rows = implode("\n", array_map(fn (Chunk $chunk) => $chunk->content, $parts));
        expect(substr_count($rows, 'Illuminate\Contracts\Service'))->toBe(30)
            ->and($parts[0]->content)->toContain("Below you will find every facade and its underlying class.\n\n| Facade");
    });

    it('drops lines that only link to other parts of the page', function () {
        expect(chunksByAnchor('oversized-blocks')['available-methods']->content)
            ->not->toContain('](#method-')
            ->toContain('Each method is documented below');
    });

    it('replaces embedded base64 images with their alt text', function () {
        expect(chunksByAnchor('oversized-blocks')['oauth']->content)
            ->not->toContain('base64')
            ->toContain('Authorization screen example')
            ->toContain('receives an access token');
    });
});

describe('encoding', function () {
    it('keeps multibyte characters whole', function () {
        // ✅ ends in byte 0x85, which a non-unicode \R treats as a line break.
        $markdown = "# Forms\n\n<a name=\"validation\"></a>\n## Validation\n\nValid ✅ and Å, then\r\nmore text.\n";

        $chunk = (new MarkdownChunker(800, 0))->chunk($markdown)[0];

        expect(mb_check_encoding($chunk->embeddingText(), 'UTF-8'))->toBeTrue()
            ->and($chunk->content)->toContain('Valid ✅ and Å, then')
            ->and($chunk->content)->not->toContain("\r");
    });
});
