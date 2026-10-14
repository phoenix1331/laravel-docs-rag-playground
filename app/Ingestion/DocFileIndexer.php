<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Chunking\Chunk;
use App\Chunking\MarkdownChunker;
use App\Contracts\Embedder;
use App\Docs\DocsRepository;
use App\Models\DocChunk;
use App\Models\DocFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brings the stored chunks for one docs page in line with its current Markdown, embedding only what changed.
 */
class DocFileIndexer
{
    public function __construct(
        protected DocsRepository $docs,
        protected MarkdownChunker $chunker,
        protected Embedder $embedder,
    ) {}

    /**
     * Changes whenever the chunker rules, chunk limits or embedding model change.
     */
    public function signature(): string
    {
        return sha1($this->chunker->signature().'|'.$this->embedder->model());
    }

    public function index(string $version, string $file): IndexResult
    {
        $contents = $this->docs->contents($version, $file);
        $chunks = $this->chunker->chunk($contents);
        $model = $this->embedder->model();

        $existing = DocChunk::forVersion($version)
            ->where('file', $file)
            ->get(['id', 'anchor', 'hash', 'embedding_model'])
            ->keyBy('anchor');

        $current = $existing->filter(fn (DocChunk $row) => $row->embedding_model === $model);

        [$unchanged, $changed] = collect($chunks)->partition(
            fn (Chunk $chunk) => $current->get($chunk->anchor)?->hash === $chunk->hash,
        );

        $reusable = $this->reusableEmbeddings($version, $file, $model, $changed);
        [$reused, $toEmbed] = $changed->partition(fn (Chunk $chunk) => $reusable->has($chunk->hash));

        $embeddings = $reusable->all();

        if ($toEmbed->isNotEmpty()) {
            $vectors = $this->embedder->embedDocuments($toEmbed->map(fn (Chunk $chunk) => $chunk->embeddingText())->values()->all());
            $embeddings += $toEmbed->values()->mapWithKeys(fn (Chunk $chunk, int $i) => [$chunk->hash => $vectors[$i]])->all();
        }

        $stale = $existing->keys()->diff(array_map(fn (Chunk $chunk) => $chunk->anchor, $chunks));

        DB::transaction(function () use ($version, $file, $model, $changed, $embeddings, $stale) {
            $this->upsert($version, $file, $model, $changed, $embeddings);

            if ($stale->isNotEmpty()) {
                DocChunk::forVersion($version)->where('file', $file)->whereIn('anchor', $stale->all())->delete();
            }
        });

        DocFile::updateOrCreate(
            ['version' => $version, 'file' => $file],
            [
                'blob_hash' => DocsRepository::gitBlobHash($contents),
                'signature' => $this->signature(),
                'chunk_count' => count($chunks),
                'ingested_at' => now(),
            ],
        );

        return new IndexResult(
            embedded: $toEmbed->count(),
            reused: $reused->count(),
            unchanged: $unchanged->count(),
            deleted: $stale->count(),
        );
    }

    /**
     * Embeddings already stored for this page under another anchor (e.g. a section that moved), keyed by hash.
     *
     * @param  Collection<int, Chunk>  $changed
     * @return Collection<string, list<float>>
     */
    protected function reusableEmbeddings(string $version, string $file, string $model, Collection $changed): Collection
    {
        if ($changed->isEmpty()) {
            return collect();
        }

        return DocChunk::forVersion($version)
            ->where('file', $file)
            ->where('embedding_model', $model)
            ->whereIn('hash', $changed->map(fn (Chunk $chunk) => $chunk->hash)->all())
            ->get(['hash', 'embedding'])
            ->mapWithKeys(fn (DocChunk $row) => [$row->hash => $row->embedding]);
    }

    /**
     * @param  Collection<int, Chunk>  $chunks
     * @param  array<string, list<float>>  $embeddings
     */
    protected function upsert(string $version, string $file, string $model, Collection $chunks, array $embeddings): void
    {
        if ($chunks->isEmpty()) {
            return;
        }

        $now = now();

        $rows = $chunks->map(fn (Chunk $chunk) => [
            'version' => $version,
            'file' => $file,
            'heading' => $chunk->heading,
            'heading_path' => $chunk->headingPath,
            'anchor' => $chunk->anchor,
            'url' => $this->docs->url($version, $file, $chunk->sectionAnchor),
            'content' => $chunk->content,
            'token_count' => $chunk->tokenCount,
            'hash' => $chunk->hash,
            'embedding_model' => $model,
            // pgvector accepts the JSON array literal, and SQLite stores it as JSON.
            'embedding' => json_encode($embeddings[$chunk->hash]),
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        DB::table('doc_chunks')->upsert(
            $rows,
            uniqueBy: ['version', 'file', 'anchor'],
            update: ['heading', 'heading_path', 'url', 'content', 'token_count', 'hash', 'embedding_model', 'embedding', 'updated_at'],
        );
    }
}
