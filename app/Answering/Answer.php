<?php

declare(strict_types=1);

namespace App\Answering;

use App\Retrieval\RetrievalResult;
use App\Retrieval\RetrievedChunk;

final readonly class Answer
{
    public const string ANSWERED = 'answered';

    public const string NOT_COVERED = 'not_covered';

    public const string OFFLINE = 'offline';

    /**
     * @param  list<int>  $citedNumbers  1-based section numbers cited in the text.
     */
    public function __construct(
        public string $question,
        public string $status,
        public string $text,
        public RetrievalResult $retrieval,
        public array $citedNumbers = [],
        public ?string $provider = null,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    /**
     * The retrieved sections the answer cites, in citation order.
     *
     * @return list<RetrievedChunk>
     */
    public function citations(): array
    {
        return array_values(array_map(fn (int $number) => $this->retrieval->chunks[$number - 1], $this->citedNumbers));
    }

    public function cites(RetrievedChunk $chunk): bool
    {
        return in_array($chunk->rank, $this->citedNumbers, true);
    }

    public function isCovered(): bool
    {
        return $this->status !== self::NOT_COVERED;
    }
}
