<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Sleep;

/**
 * Spaces embedding requests evenly to stay under a provider's requests-per-minute and
 * tokens-per-minute limits, shared across every worker through the cache.
 *
 * Each request reserves the next free slot and pushes the following slot back by
 * whichever limit is tighter for its size, so there are no bursts at window edges.
 */
class EmbeddingThrottle
{
    public function __construct(
        protected Repository $cache,
        protected int $requestsPerMinute = 0,
        protected int $tokensPerMinute = 0,
        protected string $key = 'rag:embeddings:next-slot',
    ) {}

    public function enabled(): bool
    {
        return $this->requestsPerMinute > 0 || $this->tokensPerMinute > 0;
    }

    /**
     * Block until a request of roughly this many tokens may be sent.
     */
    public function wait(int $tokens): void
    {
        if (! $this->enabled()) {
            return;
        }

        $slot = $this->cache->lock("{$this->key}:lock", 10)->block(10, function () use ($tokens) {
            $now = microtime(true);
            $slot = max($now, (float) $this->cache->get($this->key, 0));

            $this->cache->put($this->key, $slot + $this->interval($tokens), now()->addHour());

            return $slot;
        });

        $delay = $slot - microtime(true);

        if ($delay > 0) {
            Sleep::for((int) ceil($delay * 1000))->milliseconds();
        }
    }

    /**
     * Seconds this request occupies under the tighter of the two limits.
     */
    public function interval(int $tokens): float
    {
        $byRequests = $this->requestsPerMinute > 0 ? 60 / $this->requestsPerMinute : 0.0;
        $byTokens = $this->tokensPerMinute > 0 ? 60 * $tokens / $this->tokensPerMinute : 0.0;

        return max($byRequests, $byTokens);
    }
}
