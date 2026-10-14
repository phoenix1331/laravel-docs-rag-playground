<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Tests that need pgvector or tsvector; run them with "make test-pgsql".
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('pgsql')
    ->beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL with pgvector (make test-pgsql).');
        }
    })
    ->in('Pgsql');

/**
 * Cosine similarity of two unit-length vectors.
 *
 * @param  list<float>  $a
 * @param  list<float>  $b
 */
function cosineSimilarity(array $a, array $b): float
{
    return array_sum(array_map(fn (float $x, float $y) => $x * $y, $a, $b));
}

/**
 * Point the docs repository at a scratch directory for the test.
 */
function useScratchDocs(): string
{
    $path = storage_path('framework/testing/docs');
    File::deleteDirectory($path);
    config()->set('rag.docs.path', $path);

    return $path;
}

function writeDocsPage(string $version, string $file, string $markdown): void
{
    $directory = config('rag.docs.path')."/{$version}";
    File::ensureDirectoryExists($directory);
    File::put("{$directory}/{$file}", $markdown);
}

function docsPage(string $title, array $sections): string
{
    $markdown = "# {$title}\n";

    foreach ($sections as $anchor => $text) {
        $heading = ucwords(str_replace('-', ' ', $anchor));
        $markdown .= "\n<a name=\"{$anchor}\"></a>\n## {$heading}\n\n{$text}\n";
    }

    return $markdown;
}
