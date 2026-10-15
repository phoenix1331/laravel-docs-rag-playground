<?php

declare(strict_types=1);

use App\Contracts\Embedder;
use App\Models\DocChunk;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    foreach ([
        ['queues.md', 'job-batching', 'job-batching', 'Dispatch a batch of jobs and run a callback when every job in the batch has finished'],
        ['routing.md', 'basic-routing', 'basic-routing', 'Define a route in routes web php using the Route facade'],
        ['cache.md', 'atomic-locks-part-2', 'atomic-locks', 'Atomic locks let only one process run code at a time across servers'],
    ] as [$file, $anchor, $fragment, $content]) {
        DocChunk::factory()->create([
            'file' => $file,
            'anchor' => $anchor,
            'url' => "https://laravel.com/docs/13.x/{$file}#{$fragment}",
            'content' => $content,
            'embedding' => app(Embedder::class)->embedDocuments([$content])[0],
        ]);
    }

    $this->evalFile = storage_path('framework/testing/eval.json');
    File::ensureDirectoryExists(dirname($this->evalFile));
    File::put($this->evalFile, json_encode(['questions' => [
        ['id' => 'batch', 'version' => '13.x', 'question' => 'Run a callback when every job in the batch has finished', 'expected' => ['queues.md#job-batching']],
        ['id' => 'locks', 'version' => '13.x', 'question' => 'Only one process run code at a time across servers', 'expected' => ['cache.md#atomic-locks']],
        ['id' => 'mail', 'version' => '13.x', 'question' => 'Attach a PDF to an email', 'expected' => ['mail.md#attachments']],
        ['id' => 'django', 'version' => '13.x', 'question' => 'Django foreign key model', 'expected' => [], 'unanswerable' => true],
        ['id' => 'old', 'version' => '12.x', 'question' => 'Ignored for 13.x', 'expected' => ['queues.md#job-batching']],
    ]]));
});

afterEach(fn () => isset($this->evalFile) && File::delete($this->evalFile));

it('reports recall, mrr, misses and unanswerable outcomes', function () {
    $this->artisan('rag:eval', ['--file' => $this->evalFile, '--k' => 2])
        ->expectsOutputToContain('vector retrieval on 13.x, k = 2: 3 answerable and 1 unanswerable questions')
        ->expectsOutputToContain('66.7% (2 of 3)')
        ->expectsOutputToContain('0.667')
        ->expectsOutputToContain('mail.md#attachments')
        ->expectsOutputToContain('django')
        ->assertSuccessful();
});

it('matches split sections by their url fragment', function () {
    $this->artisan('rag:eval', ['--file' => $this->evalFile, '--k' => 1])
        ->doesntExpectOutputToContain('Only one process run code')
        ->assertSuccessful();
});

it('embeds each question for the version once', function () {
    $embedder = app(Embedder::class);
    $before = count($embedder->embedded);

    $this->artisan('rag:eval', ['--file' => $this->evalFile])->assertSuccessful();

    expect(array_slice($embedder->embedded, $before))->toHaveCount(4);
});
