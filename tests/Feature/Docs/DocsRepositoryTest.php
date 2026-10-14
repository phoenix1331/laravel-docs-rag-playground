<?php

declare(strict_types=1);

use App\Docs\DocsRepository;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->path = storage_path('framework/testing/docs-repository');
    File::deleteDirectory($this->path);
    config()->set('rag.docs.path', $this->path);

    $this->repository = app(DocsRepository::class);
});

afterEach(fn () => File::deleteDirectory($this->path));

it('shallow clones a version branch that is not checked out yet', function () {
    Process::preventStrayProcesses()->fake(['*rev-parse*' => Process::result("abc123\n"), '*' => Process::result()]);

    expect($this->repository->sync('13.x'))->toBe('abc123');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'git', 'clone', '--depth', '1', '--single-branch', '--branch', '13.x', 'https://github.com/laravel/docs.git', "{$this->path}/13.x",
    ]);
});

it('fetches and resets an existing clone', function () {
    File::ensureDirectoryExists("{$this->path}/13.x/.git");
    Process::preventStrayProcesses()->fake(['*rev-parse*' => Process::result('def456'), '*' => Process::result()]);

    expect($this->repository->sync('13.x'))->toBe('def456');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'fetch', '--depth', '1', 'origin', '13.x']
        && $process->path === "{$this->path}/13.x");
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'reset', '--hard', 'FETCH_HEAD']);
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'clone');
});

it('lists docs pages and skips files that are not pages', function () {
    File::ensureDirectoryExists("{$this->path}/13.x");
    foreach (['queues.md', 'routing.md', 'readme.md', 'license.md', 'documentation.md', 'notes.txt'] as $file) {
        File::put("{$this->path}/13.x/{$file}", '# Page');
    }

    expect($this->repository->files('13.x'))->toBe(['queues.md', 'routing.md']);
});

it('builds laravel.com section links', function () {
    expect($this->repository->url('13.x', 'queues.md', 'job-batching'))
        ->toBe('https://laravel.com/docs/13.x/queues#job-batching');
});

it('rejects versions that are not configured', function () {
    $this->repository->files('../../etc');
})->throws(InvalidArgumentException::class, 'Unknown docs version');
