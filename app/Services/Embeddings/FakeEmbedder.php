<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Contracts\Embedder;

/**
 * Deterministic, offline embedder using feature hashing: each word is hashed to a
 * dimension, so texts sharing words get a higher cosine similarity. Good enough to
 * exercise retrieval in tests without calling an API.
 */
class FakeEmbedder implements Embedder
{
    /**
     * The texts embedded so far, for asserting what was (or was not) sent.
     *
     * @var list<string>
     */
    public array $embedded = [];

    public function __construct(protected int $dimensions = 1024) {}

    public function embedDocuments(array $texts): array
    {
        return array_map(fn (string $text) => $this->vector($text), $texts);
    }

    public function embedQuery(string $text): array
    {
        return $this->vector($text);
    }

    public function embedQueries(array $texts): array
    {
        return array_map(fn (string $text) => $this->vector($text), $texts);
    }

    public function model(): string
    {
        return 'fake';
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Build a unit-length vector from the hashed words of the text.
     *
     * @return list<float>
     */
    protected function vector(string $text): array
    {
        $this->embedded[] = $text;

        $vector = array_fill(0, $this->dimensions, 0.0);
        $words = preg_split('/[^a-z0-9]+/', strtolower($text), flags: PREG_SPLIT_NO_EMPTY) ?: [$text];

        foreach ($words as $word) {
            $hash = crc32($word);
            $vector[$hash % $this->dimensions] += ($hash & 1) === 1 ? 1.0 : -1.0;
        }

        $length = sqrt(array_sum(array_map(fn (float $value) => $value ** 2, $vector)));

        if ($length === 0.0) {
            $vector[crc32($text) % $this->dimensions] = 1.0;

            return $vector;
        }

        return array_map(fn (float $value) => $value / $length, $vector);
    }
}
