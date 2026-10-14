<?php

declare(strict_types=1);

use App\Contracts\Embedder;
use App\Models\DocChunk;
use App\Models\DocFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    useScratchDocs();
    config()->set('rag.chunking.min_tokens', 0);

    writeDocsPage('13.x', 'queues.md', docsPage('Queues', [
        'job-batching' => 'Job batching runs a group of jobs and fires a callback when they all finish.',
        'job-chaining' => 'Job chaining runs jobs one after another, stopping if one fails.',
    ]));
    writeDocsPage('13.x', 'routing.md', docsPage('Routing', [
        'basic-routing' => 'Routes are defined in the routes directory using the Route facade.',
    ]));
    writeDocsPage('13.x', 'readme.md', '# Laravel Documentation');
});

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/docs')));

it('ingests every page of a version and reports what it did', function () {
    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])
        ->expectsOutputToContain('Dispatched 2 of 2 pages for 13.x')
        ->expectsOutputToContain('Ingest complete')
        ->assertSuccessful();

    expect(DocChunk::forVersion('13.x')->pluck('file')->unique()->sort()->values()->all())->toBe(['queues.md', 'routing.md'])
        ->and(DocChunk::count())->toBe(3);
});

it('embeds nothing on a second run', function () {
    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();
    $calls = count(app(Embedder::class)->embedded);

    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

    expect(app(Embedder::class)->embedded)->toHaveCount($calls);
});

it('removes chunks for pages deleted from the docs', function () {
    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

    File::delete(config('rag.docs.path').'/13.x/routing.md');
    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

    expect(DocChunk::pluck('file')->unique()->all())->toBe(['queues.md']);
});

it('refuses to mix embedding models unless run fresh', function () {
    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();
    DocChunk::query()->update(['embedding_model' => 'voyage-4']);

    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])
        ->expectsOutputToContain('re-run with --fresh')
        ->assertFailed();

    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true, '--fresh' => true])
        ->expectsOutputToContain('Deleted 3 existing chunks')
        ->assertSuccessful();

    expect(DocChunk::where('embedding_model', 'fake')->count())->toBe(3);
});

it('only touches the requested version', function () {
    $other = DocChunk::factory()->version('12.x')->create(['file' => 'queues.md']);

    $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true, '--fresh' => true])->assertSuccessful();

    expect($other->fresh())->not->toBeNull();
});

it('syncs the docs repository before ingesting', function () {
    Process::preventStrayProcesses()->fake(['*rev-parse*' => Process::result('abc123'), '*' => Process::result()]);
    File::ensureDirectoryExists(config('rag.docs.path').'/13.x/.git');

    $this->artisan('docs:ingest', ['version' => '13.x'])
        ->expectsOutputToContain('abc123')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'fetch', '--depth', '1', 'origin', '13.x']);
});

it('rejects unknown versions', function () {
    $this->artisan('docs:ingest', ['version' => '9.x', '--no-pull' => true])
        ->expectsOutputToContain('Unknown version [9.x]')
        ->assertFailed();
});

describe('unchanged pages', function () {
    it('records each ingested page with its git blob hash', function () {
        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

        $page = DocFile::where('file', 'queues.md')->sole();
        $contents = File::get(config('rag.docs.path').'/13.x/queues.md');

        expect($page->blob_hash)->toBe(sha1('blob '.strlen($contents)."\0".$contents))
            ->and($page->chunk_count)->toBe(2);
    });

    it('dispatches nothing when no page has changed', function () {
        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])
            ->expectsOutputToContain('All 2 pages for 13.x are up to date')
            ->assertSuccessful();
    });

    it('dispatches only the pages that changed', function () {
        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

        writeDocsPage('13.x', 'routing.md', docsPage('Routing', [
            'basic-routing' => 'Routes are defined in the routes directory using the Route facade and closures.',
        ]));

        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])
            ->expectsOutputToContain('Dispatched 1 of 2 pages')
            ->assertSuccessful();

        expect(DocChunk::where('file', 'routing.md')->sole()->content)->toContain('and closures');
    });

    it('re-processes every page when the chunking settings change', function () {
        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

        config()->set('rag.chunking.max_tokens', 500);

        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])
            ->expectsOutputToContain('Dispatched 2 of 2 pages')
            ->assertSuccessful();
    });

    it('re-processes every page with --all', function () {
        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true, '--all' => true])
            ->expectsOutputToContain('Dispatched 2 of 2 pages')
            ->assertSuccessful();
    });

    it('removes the page record when a page is deleted', function () {
        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])->assertSuccessful();

        File::delete(config('rag.docs.path').'/13.x/routing.md');

        $this->artisan('docs:ingest', ['version' => '13.x', '--no-pull' => true])
            ->expectsOutputToContain('Removed 1 chunks from pages no longer in 13.x')
            ->assertSuccessful();

        expect(DocFile::pluck('file')->all())->toBe(['queues.md']);
    });
});
