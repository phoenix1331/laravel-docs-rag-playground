<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Contracts\Embedder;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Providers\Provider;
use UnexpectedValueException;

class AiSdkEmbedder implements Embedder
{
    public function __construct(
        protected string $provider,
        protected string $model,
        protected int $dimensions,
        protected int $batchSize = 64,
    ) {}

    public function embedDocuments(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk($texts, max(1, $this->batchSize)) as $batch) {
            array_push($vectors, ...$this->generate($batch, 'document'));
        }

        return $vectors;
    }

    public function embedQuery(string $text): array
    {
        return $this->generate([$text], 'query')[0];
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
    protected function generate(array $inputs, string $inputType): array
    {
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
