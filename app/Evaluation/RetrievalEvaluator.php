<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Contracts\Embedder;
use App\Models\DocChunk;
use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;

/**
 * Scores retrieval alone against the eval set: no chat model is called, so a run costs one embedding request.
 */
class RetrievalEvaluator
{
    public function __construct(
        protected Retriever $retriever,
        protected Embedder $embedder,
        protected float $threshold,
    ) {}

    /**
     * @param  list<EvalQuestion>  $questions
     */
    public function evaluate(array $questions, string $version, string $mode, int $k): EvalReport
    {
        $embeddings = $this->embedder->embedQueries(array_map(fn (EvalQuestion $question) => $question->question, $questions));

        $results = array_map(function (EvalQuestion $question, array $embedding) use ($version, $mode, $k) {
            $retrieval = $this->retriever->retrieve($question->question, $version, $mode, $k, $embedding);
            $retrieved = array_map(fn (RetrievedChunk $hit) => self::sectionKey($hit->chunk), $retrieval->chunks);

            return new QuestionResult(
                $question,
                $retrieved,
                RetrievalMetrics::firstRelevantRank($retrieved, $question->expected),
                $retrieval->bestSimilarity(),
            );
        }, $questions, $embeddings);

        return new EvalReport($version, $mode, $k, $this->threshold, $results);
    }

    /**
     * "page.md#anchor" using the URL fragment, so every part of a split section maps to the section itself.
     */
    public static function sectionKey(DocChunk $chunk): string
    {
        return $chunk->file.'#'.(parse_url($chunk->url, PHP_URL_FRAGMENT) ?? $chunk->anchor);
    }
}
