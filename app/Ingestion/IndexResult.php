<?php

declare(strict_types=1);

namespace App\Ingestion;

final readonly class IndexResult
{
    public function __construct(
        public int $embedded = 0,
        public int $reused = 0,
        public int $unchanged = 0,
        public int $deleted = 0,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
