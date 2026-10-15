<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Evaluation\EvalSet;
use App\Evaluation\QuestionResult;
use App\Evaluation\RetrievalEvaluator;
use App\Models\DocChunk;
use App\Retrieval\Retriever;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('rag:eval
    {--mode= : Retrieval mode (defaults to RAG_RETRIEVAL_MODE)}
    {--k=5 : How many results count towards recall}
    {--docs-version= : Docs version to evaluate (defaults to RAG_DEFAULT_VERSION)}
    {--file= : Eval set JSON (defaults to database/eval/questions.json)}')]
#[Description('Score retrieval against the eval set with recall@k and MRR, without calling a chat model')]
class EvaluateRetrieval extends Command
{
    public function handle(RetrievalEvaluator $evaluator): int
    {
        $version = $this->option('docs-version') ?? config('rag.docs.default_version');
        $mode = $this->option('mode') ?? config('rag.retrieval.mode');
        $k = (int) $this->option('k');

        if (! in_array($mode, Retriever::MODES, true)) {
            $this->components->error("Unknown retrieval mode [{$mode}]. Use one of: ".implode(', ', Retriever::MODES).'.');

            return self::FAILURE;
        }

        if ($k < 1) {
            $this->components->error('--k must be at least 1.');

            return self::FAILURE;
        }

        try {
            $questions = EvalSet::fromFile($this->option('file') ?? database_path('eval/questions.json'))->forVersion($version);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($questions === []) {
            $this->components->error("The eval set has no questions for {$version}.");

            return self::FAILURE;
        }

        if (! DocChunk::forVersion($version)->exists()) {
            $this->components->warn("No chunks stored for {$version}. Run docs:ingest {$version} first.");

            return self::FAILURE;
        }

        $report = $evaluator->evaluate($questions, $version, $mode, $k);
        $answerable = count($report->answerable());

        $this->components->info(sprintf('%s retrieval on %s, k = %d: %d answerable and %d unanswerable questions', $mode, $version, $k, $answerable, count($report->unanswerable())));

        $this->table(['Metric', 'Value'], [
            ["Recall@{$k}", sprintf('%.1f%% (%d of %d)', $report->recallAtK() * 100, $answerable - count($report->misses()), $answerable)],
            ['MRR', sprintf('%.3f', $report->meanReciprocalRank())],
            [sprintf('Answerable under threshold (%.2f)', $report->threshold), count($report->wronglyBelowThreshold())],
        ]);

        $this->misses($report->misses());
        $this->unanswerable($report->unanswerable(), $report->threshold);

        return self::SUCCESS;
    }

    /**
     * @param  list<QuestionResult>  $misses
     */
    protected function misses(array $misses): void
    {
        if ($misses === []) {
            $this->components->info('No misses: every answerable question found an expected section.');

            return;
        }

        $this->newLine();
        $this->line('<fg=yellow>Misses</>');
        $this->table(
            ['Question', 'Expected', 'Retrieved instead', 'Best'],
            array_map(fn (QuestionResult $result) => [
                $result->question->id."\n".Str::limit($result->question->question, 60),
                implode("\n", $result->question->expected),
                implode("\n", array_slice($result->retrieved, 0, 3)),
                $result->bestSimilarity === null ? '-' : number_format($result->bestSimilarity, 3),
            ], $misses),
        );
    }

    /**
     * @param  list<QuestionResult>  $results
     */
    protected function unanswerable(array $results, float $threshold): void
    {
        if ($results === []) {
            return;
        }

        $this->newLine();
        $this->line('<fg=yellow>Unanswerable</>');
        $this->table(
            ['Question', 'Best', 'Outcome'],
            array_map(fn (QuestionResult $result) => [
                $result->question->id,
                $result->bestSimilarity === null ? '-' : number_format($result->bestSimilarity, 3),
                $result->belowThreshold($threshold) ? 'refused by threshold' : 'reaches the model, relies on NOT_COVERED',
            ], $results),
        );
    }
}
