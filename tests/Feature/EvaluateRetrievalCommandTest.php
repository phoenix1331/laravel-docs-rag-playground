<?php

declare(strict_types=1);

use App\Models\DocChunk;

it('rejects unknown retrieval modes before embedding anything', function () {
    $this->artisan('rag:eval', ['--mode' => 'keyword'])
        ->expectsOutputToContain('Unknown retrieval mode [keyword]')
        ->assertFailed();
});

it('rejects a k below one', function () {
    $this->artisan('rag:eval', ['--k' => 0])
        ->expectsOutputToContain('--k must be at least 1')
        ->assertFailed();
});

it('fails clearly when the eval set is missing', function () {
    $this->artisan('rag:eval', ['--file' => '/nowhere/questions.json'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

it('fails when the eval set has no questions for the version', function () {
    $this->artisan('rag:eval', ['--docs-version' => '12.x'])
        ->expectsOutputToContain('no questions for 12.x')
        ->assertFailed();
});

it('fails clearly when the version has not been ingested', function () {
    expect(DocChunk::count())->toBe(0);

    $this->artisan('rag:eval')
        ->expectsOutputToContain('Run docs:ingest 13.x first')
        ->assertFailed();
});
