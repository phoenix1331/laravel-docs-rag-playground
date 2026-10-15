<?php

declare(strict_types=1);

namespace App\Retrieval;

use App\Contracts\Embedder;
use App\Models\DocChunk;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Retriever
{
    public const array MODES = ['vector', 'hybrid'];

    /**
     * Hybrid mode fuses this many candidates per k from each search, so a section ranked low by one
     * search but high by the other can still reach the final top k.
     */
    public const int CANDIDATES_PER_RESULT = 4;

    protected const array COLUMNS = ['id', 'version', 'file', 'heading', 'heading_path', 'anchor', 'url', 'content', 'token_count'];

    protected bool $iterativeScanEnabled = false;

    public function __construct(
        protected Embedder $embedder,
        protected string $defaultMode = 'vector',
        protected int $defaultTopK = 6,
        protected int $rrfK = 60,
    ) {}

    /**
     * Find the chunks of one docs version most relevant to the query.
     *
     * The version filter is part of the SQL query itself, so chunks from another version can never be returned.
     * Pass $queryEmbedding when the query was already embedded, for example as part of a batch.
     *
     * @param  list<float>|null  $queryEmbedding
     */
    public function retrieve(string $query, string $version, ?string $mode = null, ?int $topK = null, ?array $queryEmbedding = null): RetrievalResult
    {
        $mode ??= $this->defaultMode;
        $topK ??= $this->defaultTopK;

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown retrieval mode [{$mode}]. Use one of: ".implode(', ', self::MODES).'.');
        }

        $embedding = $queryEmbedding ?? $this->embedder->embedQuery($query);

        $chunks = match ($mode) {
            'vector' => $this->vector($embedding, $version, $topK),
            'hybrid' => $this->hybrid($query, $embedding, $version, $topK),
        };

        return new RetrievalResult($query, $version, $mode, $chunks);
    }

    /**
     * Rank chunks by cosine similarity between their embedding and the query's embedding.
     *
     * @param  list<float>  $embedding
     * @return list<RetrievedChunk>
     */
    protected function vector(array $embedding, string $version, int $topK): array
    {
        $this->enableIterativeScan();

        $rows = DocChunk::forVersion($version)
            ->select(self::COLUMNS)
            ->selectVectorDistance('embedding', $embedding, as: 'distance')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.0)
            ->limit($topK)
            ->get();

        return $rows->values()->map(function (DocChunk $chunk, int $index) {
            $similarity = 1 - (float) $chunk->getAttribute('distance');

            return new RetrievedChunk($chunk, rank: $index + 1, score: $similarity, similarity: $similarity);
        })->all();
    }

    /**
     * Run vector and full-text search separately and merge the two rankings with reciprocal rank fusion.
     *
     * The score is the fused RRF score; similarity is still the cosine similarity, so the refusal
     * threshold and the relevance ruler mean the same thing in both modes.
     *
     * @param  list<float>  $embedding
     * @return list<RetrievedChunk>
     */
    protected function hybrid(string $query, array $embedding, string $version, int $topK): array
    {
        $candidates = $topK * self::CANDIDATES_PER_RESULT;

        $vector = collect($this->vector($embedding, $version, $candidates))->keyBy(fn (RetrievedChunk $hit) => $hit->chunk->id);
        $keyword = $this->keywordRanking($query, $version, $candidates);

        $fused = array_slice(ReciprocalRankFusion::fuse([$vector->keys()->all(), $keyword], $this->rrfK), 0, $topK, preserve_keys: true);

        $keywordOnly = array_diff(array_keys($fused), $vector->keys()->all());
        $chunks = $vector->map(fn (RetrievedChunk $hit) => $hit->chunk)
            ->union($keywordOnly === [] ? [] : $this->withDistance($keywordOnly, $embedding)->keyBy('id'));
        $keywordRanks = array_flip($keyword);

        return collect($fused)->keys()->values()->map(function (int $id, int $index) use ($fused, $chunks, $vector, $keywordRanks) {
            $chunk = $chunks[$id];
            $similarity = $vector->has($id) ? $vector[$id]->similarity : 1 - (float) $chunk->getAttribute('distance');

            return new RetrievedChunk(
                $chunk,
                rank: $index + 1,
                score: $fused[$id],
                similarity: $similarity,
                vectorRank: $vector->has($id) ? $vector[$id]->rank : null,
                keywordRank: isset($keywordRanks[$id]) ? $keywordRanks[$id] + 1 : null,
            );
        })->all();
    }

    /**
     * Chunk ids ranked by full-text relevance to the query.
     *
     * Any query word may match (OR): requiring every word, as websearch_to_tsquery does, matches almost
     * nothing for a full question. ts_rank normalisation 1 divides by 1 + log(length) so long sections
     * do not win just by repeating common words.
     *
     * @return list<int>
     */
    protected function keywordRanking(string $query, string $version, int $limit): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $config = DocChunk::TEXT_SEARCH_CONFIG;
        $tsquery = "replace(plainto_tsquery('{$config}', ?)::text, ' & ', ' | ')::tsquery";

        return DocChunk::forVersion($version)
            ->whereRaw("search_vector @@ {$tsquery}", [$query])
            ->orderByRaw("ts_rank(search_vector, {$tsquery}, 1) desc", [$query])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Load chunks by id with their cosine distance to the query, for sections only full-text search found.
     *
     * @param  list<int>  $ids
     * @param  list<float>  $embedding
     * @return Collection<int, DocChunk>
     */
    protected function withDistance(array $ids, array $embedding): Collection
    {
        return DocChunk::query()
            ->select(self::COLUMNS)
            ->selectVectorDistance('embedding', $embedding, as: 'distance')
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * The HNSW index gathers a fixed number of nearest candidates before the version filter is applied,
     * which can leave fewer than k results once several versions are stored. Iterative scans (pgvector
     * 0.8+) keep searching the index until enough rows pass the filter.
     */
    protected function enableIterativeScan(): void
    {
        if ($this->iterativeScanEnabled || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('set hnsw.iterative_scan = strict_order');
        $this->iterativeScanEnabled = true;
    }
}
