<?php

declare(strict_types=1);

namespace App\Ingestion;

use Illuminate\Support\Facades\Cache;

/**
 * Running totals for an ingest batch, so the command can report what the queued jobs did.
 */
class IngestStats
{
    public const array METRICS = ['embedded', 'reused', 'unchanged', 'deleted'];

    public static function record(string $batchId, IndexResult $result): void
    {
        foreach ($result->toArray() as $metric => $count) {
            self::add($batchId, $metric, $count);
        }
    }

    public static function add(string $batchId, string $metric, int $count): void
    {
        Cache::add(self::key($batchId, $metric), 0, now()->addDay());
        Cache::increment(self::key($batchId, $metric), $count);
    }

    /**
     * @return array<string, int>
     */
    public static function totals(string $batchId): array
    {
        return array_combine(self::METRICS, array_map(
            fn (string $metric) => (int) Cache::get(self::key($batchId, $metric), 0),
            self::METRICS,
        ));
    }

    protected static function key(string $batchId, string $metric): string
    {
        return "rag:ingest:{$batchId}:{$metric}";
    }
}
