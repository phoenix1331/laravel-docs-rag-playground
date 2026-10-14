<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Embedder;
use App\Docs\DocsRepository;
use App\Ingestion\DocFileIndexer;
use App\Ingestion\IngestStats;
use App\Jobs\IngestDocFile;
use App\Models\DocChunk;
use App\Models\DocFile;
use Illuminate\Bus\Batch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

#[Signature('docs:ingest
    {version? : Docs branch to ingest, e.g. 13.x (defaults to RAG_DEFAULT_VERSION)}
    {--fresh : Delete every chunk for the version first}
    {--all : Re-process every page, even ones unchanged since the last ingest}
    {--no-pull : Use the local clone without fetching from GitHub}
    {--no-wait : Dispatch the batch and exit without waiting for it}')]
#[Description('Chunk, embed and store a version of the Laravel docs')]
class IngestDocs extends Command
{
    public function handle(DocsRepository $docs, Embedder $embedder, DocFileIndexer $indexer): int
    {
        $version = $this->argument('version') ?? config('rag.docs.default_version');

        if (! in_array($version, $docs->versions(), true)) {
            $this->components->error("Unknown version [{$version}]. Known versions: ".implode(', ', $docs->versions()).'.');

            return self::FAILURE;
        }

        if (! $this->prepareSource($docs, $version)) {
            return self::FAILURE;
        }

        if (! $this->option('fresh') && $this->usesAnotherModel($version, $embedder->model())) {
            $this->components->error("Chunks for {$version} were embedded with a different model than [{$embedder->model()}]. Vectors from different models cannot be compared; re-run with --fresh.");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $deleted = DocChunk::forVersion($version)->delete();
            DocFile::forVersion($version)->delete();
            $this->components->info("Deleted {$deleted} existing chunks for {$version}.");
        }

        $files = $docs->files($version);
        $this->removeDeletedPages($version, $files);

        $pending = $this->option('all') ? $files : $this->changedPages($docs, $indexer, $version, $files);

        if ($pending === []) {
            $this->components->info(sprintf('All %d pages for %s are up to date.', count($files), $version));

            return self::SUCCESS;
        }

        $batch = $this->dispatchBatch($version, $pending);

        $this->components->info(sprintf(
            'Dispatched %d of %d pages for %s using %s (batch %s).',
            count($pending), count($files), $version, $embedder->model(), $batch->id,
        ));

        if ($this->option('no-wait')) {
            return self::SUCCESS;
        }

        $batch = $this->waitFor($batch);
        $this->report($batch, $version);

        return $batch->failedJobs > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function prepareSource(DocsRepository $docs, string $version): bool
    {
        if (! $this->option('no-pull')) {
            $commit = null;
            $this->components->task("Syncing laravel/docs {$version}", function () use ($docs, $version, &$commit) {
                $commit = $docs->sync($version);
            });
            $this->components->twoColumnDetail('Commit', (string) $commit);

            return true;
        }

        if ($docs->exists($version)) {
            return true;
        }

        $this->components->error("No local clone for {$version}; run without --no-pull first.");

        return false;
    }

    protected function usesAnotherModel(string $version, string $model): bool
    {
        return DocChunk::forVersion($version)->where('embedding_model', '!=', $model)->exists();
    }

    /**
     * Pages whose Markdown or chunking signature differs from their last successful ingest.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    protected function changedPages(DocsRepository $docs, DocFileIndexer $indexer, string $version, array $files): array
    {
        $ingested = DocFile::forVersion($version)->get(['file', 'blob_hash', 'signature'])->keyBy('file');
        $signature = $indexer->signature();

        return array_values(array_filter($files, function (string $file) use ($docs, $version, $ingested, $signature) {
            $last = $ingested->get($file);

            return $last === null
                || $last->signature !== $signature
                || $last->blob_hash !== $docs->blobHash($version, $file);
        }));
    }

    /**
     * Drop chunks and file records for pages that no longer exist in this version.
     *
     * @param  list<string>  $files
     */
    protected function removeDeletedPages(string $version, array $files): void
    {
        $chunks = DocChunk::forVersion($version)->whereNotIn('file', $files)->delete();
        $pages = DocFile::forVersion($version)->whereNotIn('file', $files)->delete();

        if ($chunks > 0 || $pages > 0) {
            $this->components->info("Removed {$chunks} chunks from pages no longer in {$version}.");
        }
    }

    /**
     * @param  list<string>  $files
     */
    protected function dispatchBatch(string $version, array $files): Batch
    {
        return Bus::batch(array_map(fn (string $file) => new IngestDocFile($version, $file), $files))
            ->name("docs:ingest {$version}")
            ->allowFailures()
            ->dispatch();
    }

    protected function waitFor(Batch $batch): Batch
    {
        if (! $batch->finished()) {
            $this->components->warn('Waiting for the queue. Make sure Horizon or a queue worker is running.');
        }

        $bar = $this->output->createProgressBar($batch->totalJobs);
        $bar->start();

        while (! $batch->finished()) {
            $bar->setProgress($batch->processedJobs() + $batch->failedJobs);
            sleep(1);
            $batch = $batch->fresh();
        }

        $bar->finish();
        $this->newLine(2);

        return $batch;
    }

    protected function report(Batch $batch, string $version): void
    {
        foreach (IngestStats::totals($batch->id) as $metric => $count) {
            $this->components->twoColumnDetail(ucfirst($metric), (string) $count);
        }

        $this->components->twoColumnDetail('Chunks stored for '.$version, (string) DocChunk::forVersion($version)->count());

        if ($batch->failedJobs > 0) {
            $this->components->error("{$batch->failedJobs} pages failed; see failed jobs (php artisan queue:failed). They will be retried on the next ingest.");

            return;
        }

        $this->components->info('Ingest complete.');
    }
}
