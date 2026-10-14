<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Answering\Answer;
use App\Answering\DocsAssistant;
use App\Retrieval\RetrievedChunk;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('docs:ask
    {question : Your question about the Laravel docs}
    {--docs-version= : Docs version to answer from (defaults to RAG_DEFAULT_VERSION)}
    {--no-stream : Wait for the full answer instead of streaming it}
    {--context : Also list every retrieved section with its score and whether it was cited}')]
#[Description('Answer a question from the Laravel docs, citing the sections used')]
class AskDocs extends Command
{
    public function handle(DocsAssistant $assistant): int
    {
        $version = $this->option('docs-version') ?? config('rag.docs.default_version');
        $stream = ! $this->option('no-stream');

        $this->newLine();

        try {
            $answer = $assistant->answer(
                $this->argument('question'),
                $version,
                $stream ? fn (string $delta) => $this->output->write($delta) : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $stream) {
            $this->output->write($answer->text);
        }

        $this->newLine(2);
        $this->sources($answer);

        if ($this->option('context')) {
            $this->retrievedContext($answer);
        }

        $this->footer($answer);

        return self::SUCCESS;
    }

    protected function sources(Answer $answer): void
    {
        if ($answer->citations() === []) {
            return;
        }

        $this->line('<options=bold>Sources</>');

        foreach ($answer->citations() as $hit) {
            $this->line(sprintf('  [%d] %s <fg=gray>%s</>', $hit->rank, $hit->chunk->heading_path, $hit->chunk->url));
        }

        $this->newLine();
    }

    protected function retrievedContext(Answer $answer): void
    {
        $this->table(
            ['#', 'Similarity', 'Cited', 'Section'],
            array_map(fn (RetrievedChunk $hit) => [
                $hit->rank,
                number_format((float) $hit->similarity, 3),
                $answer->cites($hit) ? 'yes' : '',
                $hit->chunk->heading_path,
            ], $answer->retrieval->chunks),
        );
    }

    protected function footer(Answer $answer): void
    {
        $details = match ($answer->status) {
            Answer::NOT_COVERED => $answer->provider === null
                ? sprintf('not covered, best similarity %.3f below %.2f, no model called', (float) $answer->retrieval->bestSimilarity(), config('rag.retrieval.min_similarity'))
                : "not covered, judged by {$answer->provider}",
            Answer::OFFLINE => 'offline mode, no model called',
            default => sprintf('%s %s, %d in / %d out tokens', $answer->provider, $answer->model, $answer->inputTokens, $answer->outputTokens),
        };

        $this->line("<fg=gray>{$answer->retrieval->version} · {$details}</>");
    }
}
