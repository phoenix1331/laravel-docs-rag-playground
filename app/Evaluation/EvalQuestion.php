<?php

declare(strict_types=1);

namespace App\Evaluation;

final readonly class EvalQuestion
{
    /**
     * @param  list<string>  $expected  Acceptable sections as "page.md#anchor"; any one of them counts as a hit.
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $question,
        public array $expected,
        public bool $unanswerable = false,
    ) {}

    /**
     * @param  array{id: string, version: string, question: string, expected: list<string>, unanswerable?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['version'],
            $data['question'],
            array_values($data['expected']),
            $data['unanswerable'] ?? false,
        );
    }
}
