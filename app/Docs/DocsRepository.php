<?php

declare(strict_types=1);

namespace App\Docs;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * A local shallow clone of the laravel/docs repository, one directory per version branch.
 */
class DocsRepository
{
    /**
     * Files in laravel/docs that are not documentation pages.
     */
    public const array IGNORED_FILES = ['documentation.md', 'license.md', 'readme.md'];

    /**
     * @param  list<string>  $versions
     */
    public function __construct(
        protected string $repository,
        protected string $path,
        protected string $baseUrl,
        protected array $versions,
    ) {}

    /**
     * Clone the version branch, or pull it if already cloned, and return the commit checked out.
     */
    public function sync(string $version): string
    {
        $directory = $this->directory($version);

        if (! File::isDirectory("{$directory}/.git")) {
            File::ensureDirectoryExists($this->path);

            Process::timeout(300)->run([
                'git', 'clone', '--depth', '1', '--single-branch', '--branch', $version, $this->repository, $directory,
            ])->throw();

            return $this->commit($version);
        }

        Process::path($directory)->timeout(300)->run(['git', 'fetch', '--depth', '1', 'origin', $version])->throw();
        Process::path($directory)->run(['git', 'reset', '--hard', 'FETCH_HEAD'])->throw();

        return $this->commit($version);
    }

    /**
     * The commit currently checked out for the version.
     */
    public function commit(string $version): string
    {
        return trim(Process::path($this->directory($version))->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
    }

    /**
     * The Markdown pages for a version, sorted by name.
     *
     * @return list<string>
     */
    public function files(string $version): array
    {
        $files = array_map('basename', File::glob($this->directory($version).'/*.md'));
        $files = array_values(array_diff($files, self::IGNORED_FILES));

        sort($files);

        return $files;
    }

    public function contents(string $version, string $file): string
    {
        return File::get($this->directory($version).'/'.basename($file));
    }

    public function exists(string $version): bool
    {
        return File::isDirectory($this->directory($version));
    }

    /**
     * Link to a section on laravel.com, e.g. https://laravel.com/docs/13.x/queues#job-batching.
     */
    public function url(string $version, string $file, string $anchor): string
    {
        return sprintf('%s/%s/%s#%s', rtrim($this->baseUrl, '/'), $version, basename($file, '.md'), $anchor);
    }

    /**
     * @return list<string>
     */
    public function versions(): array
    {
        return $this->versions;
    }

    public function directory(string $version): string
    {
        $this->ensureKnownVersion($version);

        return "{$this->path}/{$version}";
    }

    protected function ensureKnownVersion(string $version): void
    {
        if (! in_array($version, $this->versions, true)) {
            throw new InvalidArgumentException("Unknown docs version [{$version}]. Known versions: ".implode(', ', $this->versions).'.');
        }
    }
}
