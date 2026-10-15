<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('docs:search
    {query : The question or search text}
    {--docs-version= : Docs version to search (defaults to RAG_DEFAULT_VERSION)}
    {--k= : Number of results (defaults to RAG_TOP_K)}
    {--mode= : Retrieval mode (defaults to RAG_RETRIEVAL_MODE)}
    {--content : Print each result\'s content}')]
#[Description('Show the docs sections retrieved for a query, with scores, without calling a chat model')]
class SearchDocs extends Command
{
    public function handle(Retriever $retriever): int
    {
        $version = $this->option('docs-version') ?? config('rag.docs.default_version');
        $topK = $this->option('k') !== null ? (int) $this->option('k') : null;

        try {
            $result = $retriever->retrieve($this->argument('query'), $version, $this->option('mode'), $topK);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result->isEmpty()) {
            $this->components->warn("No chunks stored for {$version}. Run docs:ingest {$version} first.");

            return self::FAILURE;
        }

        $this->components->info(sprintf('%s search in %s, top %d', $result->mode, $result->version, count($result->chunks)));

        $hybrid = $result->mode === 'hybrid';

        $this->table(
            $hybrid ? ['#', 'RRF', 'Similarity', 'Vector #', 'Keyword #', 'Section', 'Tokens'] : ['#', 'Score', 'Section', 'Tokens'],
            array_map(fn (RetrievedChunk $hit) => [
                $hit->rank,
                number_format($hit->score, $hybrid ? 4 : 3),
                ...($hybrid ? [number_format((float) $hit->similarity, 3), $hit->vectorRank ?? '-', $hit->keywordRank ?? '-'] : []),
                Str::limit($hit->chunk->heading_path, 70)."\n".$hit->chunk->url,
                $hit->chunk->token_count,
            ], $result->chunks),
        );

        if ($this->option('content')) {
            foreach ($result->chunks as $hit) {
                $this->newLine();
                $this->line("<fg=cyan>#{$hit->rank} {$hit->chunk->heading_path}</>");
                $this->line(Str::limit($hit->chunk->content, 600));
            }
        }

        $threshold = config('rag.retrieval.min_similarity');
        $best = $result->bestSimilarity();

        if ($best !== null && $best < $threshold) {
            $this->components->warn(sprintf('Best similarity %.3f is below RAG_MIN_SIMILARITY (%.2f): this would be answered as not covered by the docs.', $best, $threshold));
        }

        return self::SUCCESS;
    }
}
