<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Chunking\MarkdownChunker;
use App\Contracts\Embedder;
use Illuminate\Support\Sleep;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Providers\Provider;
use UnexpectedValueException;

class AiSdkEmbedder implements Embedder
{
    public function __construct(
        protected string $provider,
        protected string $model,
        protected int $dimensions,
        protected int $batchSize = 64,
        protected int $batchTokens = 0,
        protected ?EmbeddingThrottle $throttle = null,
    ) {}

    public function embedDocuments(array $texts): array
    {
        $vectors = [];

        foreach ($this->batches($texts) as $batch) {
            array_push($vectors, ...$this->generate($batch, 'document'));
        }

        return $vectors;
    }

    /**
     * Group texts into requests capped by count and, when set, by estimated tokens.
     *
     * @param  list<string>  $texts
     * @return list<list<string>>
     */
    public function batches(array $texts): array
    {
        $batches = [];
        $batch = [];
        $tokens = 0;

        foreach ($texts as $text) {
            $size = MarkdownChunker::estimateTokens($text);
            $full = count($batch) >= max(1, $this->batchSize)
                || ($this->batchTokens > 0 && $batch !== [] && $tokens + $size > $this->batchTokens);

            if ($full) {
                $batches[] = $batch;
                $batch = [];
                $tokens = 0;
            }

            $batch[] = $text;
            $tokens += $size;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Queries skip the throttle so a question never queues behind a background ingest; the throttle
     * leaves spare requests for them. A rate limit is retried briefly instead.
     */
    public function embedQuery(string $text): array
    {
        foreach ([3, 8] as $delay) {
            try {
                return $this->generate([$text], 'query', throttled: false)[0];
            } catch (RateLimitedException) {
                Sleep::for($delay)->seconds();
            }
        }

        return $this->generate([$text], 'query', throttled: false)[0];
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Embed one batch of inputs, checking the provider returned what was asked for.
     *
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    protected function generate(array $inputs, string $inputType, bool $throttled = true): array
    {
        if ($throttled) {
            $this->throttle?->wait(array_sum(array_map(MarkdownChunker::estimateTokens(...), $inputs)));
        }

        $embeddings = Embeddings::for($inputs)
            ->dimensions($this->dimensions)
            ->timeout(60)
            ->withProviderOptions(fn (Provider $provider) => $this->providerOptions($provider, $inputType))
            ->generate(provider: $this->provider, model: $this->model)
            ->embeddings;

        if (count($embeddings) !== count($inputs)) {
            throw new UnexpectedValueException(sprintf('Expected %d embeddings, received %d.', count($inputs), count($embeddings)));
        }

        foreach ($embeddings as $embedding) {
            if (count($embedding) !== $this->dimensions) {
                throw new UnexpectedValueException(sprintf('Expected %d dimensions, received %d. Check RAG_EMBEDDING_DIMENSIONS matches the model.', $this->dimensions, count($embedding)));
            }
        }

        return array_values($embeddings);
    }

    /**
     * Voyage embeds documents and queries differently, which improves retrieval; other providers ignore the distinction.
     *
     * @return array<string, mixed>
     */
    protected function providerOptions(Provider $provider, string $inputType): array
    {
        if ($provider->name() !== 'voyageai') {
            return [];
        }

        return ['input_type' => $inputType];
    }
}
