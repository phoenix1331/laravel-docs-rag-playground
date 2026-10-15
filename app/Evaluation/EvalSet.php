<?php

declare(strict_types=1);

namespace App\Evaluation;

use InvalidArgumentException;

final readonly class EvalSet
{
    /**
     * @param  list<EvalQuestion>  $questions
     */
    public function __construct(public array $questions) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Eval set [{$path}] not found.");
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return new self(array_map(EvalQuestion::fromArray(...), $data['questions'] ?? []));
    }

    /**
     * @return list<EvalQuestion>
     */
    public function forVersion(string $version): array
    {
        return array_values(array_filter($this->questions, fn (EvalQuestion $question) => $question->version === $version));
    }
}
