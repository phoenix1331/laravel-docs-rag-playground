<?php

declare(strict_types=1);

namespace App\Answering;

use App\Models\Question;
use App\Retrieval\RetrievalResult;
use App\Retrieval\RetrievedChunk;
use Throwable;

/**
 * Records each question for debugging and as raw material for new eval cases.
 */
class QuestionLog
{
    public function __construct(protected bool $enabled = true) {}

    public function answered(Answer $answer, int $durationMs): void
    {
        $this->write([
            'version' => $answer->retrieval->version,
            'question' => $answer->question,
            'mode' => $answer->retrieval->mode,
            'status' => $answer->status,
            'answer' => $answer->text,
            'retrieved' => $this->retrieved($answer->retrieval, $answer),
            'cited_chunk_ids' => array_map(fn (RetrievedChunk $hit) => $hit->chunk->id, $answer->citations()),
            'best_similarity' => $answer->retrieval->bestSimilarity(),
            'provider' => $answer->provider,
            'model' => $answer->model,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'duration_ms' => $durationMs,
        ]);
    }

    public function failed(string $question, string $version, ?RetrievalResult $retrieval, Throwable $exception, int $durationMs): void
    {
        $this->write([
            'version' => $version,
            'question' => $question,
            'mode' => $retrieval?->mode ?? 'unknown',
            'status' => Question::ERROR,
            'retrieved' => $retrieval === null ? [] : $this->retrieved($retrieval),
            'cited_chunk_ids' => [],
            'best_similarity' => $retrieval?->bestSimilarity(),
            'duration_ms' => $durationMs,
            'error' => $exception::class.': '.$exception->getMessage(),
        ]);
    }

    /**
     * @return list<array{chunk_id: int|null, rank: int, similarity: float|null, cited: bool}>
     */
    protected function retrieved(RetrievalResult $retrieval, ?Answer $answer = null): array
    {
        return array_map(fn (RetrievedChunk $hit) => [
            'chunk_id' => $hit->chunk->id,
            'rank' => $hit->rank,
            'similarity' => $hit->similarity === null ? null : round($hit->similarity, 4),
            'cited' => $answer?->cites($hit) ?? false,
        ], $retrieval->chunks);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function write(array $attributes): void
    {
        if (! $this->enabled) {
            return;
        }

        // Logging must never break answering.
        rescue(fn () => Question::create($attributes + ['channel' => app()->runningInConsole() ? 'cli' : 'web']), report: true);
    }
}
