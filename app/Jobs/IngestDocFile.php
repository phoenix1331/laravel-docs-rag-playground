<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ingestion\DocFileIndexer;
use App\Ingestion\IngestStats;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

class IngestDocFile implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Rate limits on free tiers are tight, so allow plenty of attempts.
     */
    public int $tries = 8;

    public int $timeout = 300;

    public function __construct(
        public string $version,
        public string $file,
    ) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 30, 60, 120, 240, 300, 300];
    }

    public function handle(DocFileIndexer $indexer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $result = $indexer->index($this->version, $this->file);
        } catch (RateLimitedException|ProviderOverloadedException|ProviderConnectionException) {
            $backoff = $this->backoff();
            $this->release($backoff[min($this->attempts() - 1, count($backoff) - 1)]);

            return;
        }

        if ($this->batchId !== null) {
            IngestStats::record($this->batchId, $result);
        }
    }
}
