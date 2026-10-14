<?php

declare(strict_types=1);

namespace App\Contracts;

interface Embedder
{
    /**
     * Embed docs sections for storage, returning one vector per input in the same order.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedDocuments(array $texts): array;

    /**
     * Embed a search query.
     *
     * @return list<float>
     */
    public function embedQuery(string $text): array;

    /**
     * The name of the model producing the vectors, stored alongside each chunk.
     */
    public function model(): string;

    /**
     * The length of every vector this embedder returns.
     */
    public function dimensions(): int;
}
