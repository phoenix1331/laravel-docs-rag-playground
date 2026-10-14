# Context

Laravel 13 playground that answers questions about the Laravel documentation using retrieval-augmented generation (RAG). The user picks a docs version, asks a question, and gets an answer grounded in the official docs with links to the sections used. The goal is learning: every RAG step (chunking, embedding, retrieval, generation, evaluation) is visible and measurable rather than hidden behind a managed service.

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Runtime | Docker, FrankenPHP (`dunglas/frankenphp:php8.4`) |
| Database | PostgreSQL 17 with pgvector (`pgvector/pgvector:pg17`) |
| Queue / cache | Redis 8 |
| Tests | Pest, SQLite in memory by default |
| AI | Laravel AI SDK (`laravel/ai`) |
| Front end | Vite + Tailwind (Livewire to follow) |

## Services and ports

| Service | Container port | Host port | Notes |
|---|---|---|---|
| `app` | 8000 | `APP_PORT` (8000) | FrankenPHP via `php artisan dev` |
| `app` (Vite) | 5173 | `VITE_PORT` (5173) | HMR host is `localhost` |
| `pgsql` | 5432 | `FORWARD_DB_PORT` (5433) | 5432 is taken on the dev host |
| `redis` | 6379 | `FORWARD_REDIS_PORT` (6379) | Queue and cache store |

Inside the network the app reaches `pgsql:5432` and `redis:6379`.

## AI providers

Every provider is chosen in `.env`; `.env.example` lists the valid values for each key.

| Role | Providers | Default | Free options |
|---|---|---|---|
| Embeddings | `voyageai`, `openai`, `gemini`, `mistral`, `cohere`, `jina`, `ollama`, `openrouter`, `fake` | `voyageai` (`voyage-4`, 1024 dims) | Voyage free tokens, `ollama`, `fake` |
| Answers | `anthropic`, `openai`, `gemini`, `groq`, `mistral`, `deepseek`, `xai`, `openrouter`, `ollama`, `fake` | `anthropic` | `gemini` and `groq` free tiers, `ollama`, `fake` |

- `RAG_*_MODEL` empty means the provider's SDK default (resolved via `Laravel\Ai\Ai::embeddingProvider()->defaultEmbeddingsModel()`), so switching provider alone is enough
- `RAG_EMBEDDING_DIMENSIONS` sizes the vector column; the migration rejects values over 2000 (pgvector HNSW limit), so models like `gemini-embedding-2` (3072 native) must use a smaller output dimension
- Changing embedding provider, model or dimensions needs `make fresh` then a fresh ingest: vectors are not comparable across models
- `fake` embeddings: `FakeEmbedder` (word hashing), offline, no key. `fake` answers: offline templated answer from the top sections
- Embedding goes through `App\Contracts\Embedder` (`embedDocuments()`, `embedQuery()`); `AiSdkEmbedder` wraps `Laravel\Ai\Embeddings`, batches by `RAG_EMBEDDING_BATCH_SIZE`, checks counts and dimensions, and sends Voyage `input_type` document/query
- `ollama` runs on the host; the container reaches it at `host.docker.internal:11434` (`extra_hosts` in `docker-compose.yml`)
- Free tiers are busy: `gemini-3.8-flash` often returns overloaded errors, `gemini-3.1-flash-lite` is more reliable
- Claude Pro does not include API credit; the Anthropic API is billed separately via console.anthropic.com

## Docs source

`App\Docs\DocsRepository` keeps a shallow clone of `laravel/docs` per version branch in `storage/app/docs/{version}` (gitignored).

- `sync()` clones (`--depth 1 --single-branch`) or fetches and resets to the branch head; returns the commit
- `files()` lists `*.md` pages, skipping `documentation.md` (sidebar), `license.md`, `readme.md`
- `url()` builds `https://laravel.com/docs/{version}/{page}#{anchor}`
- Versions must be listed in `rag.docs.versions`; anything else is rejected (also guards the filesystem path)

## Chunking

`App\Chunking\MarkdownChunker` (framework-free) turns one docs page into `App\Chunking\Chunk` value objects.

| Rule | Detail |
|---|---|
| Split points | `##` and `###` headings outside fenced code; `####` stays in content |
| Anchor | Explicit `<a name="...">` above the heading (often differs from the heading text), slug fallback, de-duplicated per file |
| Skipped | `#` title (used as heading path root), table of contents, `<a name>` and wrapper `<div>` lines, lines made only of in-page `#links` |
| Cleaned | Base64 `data:` images replaced with `[Image: alt]` |
| Oversized | Packed by paragraph (code block = one paragraph); tables over budget split by rows with header repeated; parts get `-part-N` anchors but share `sectionAnchor` for the URL |
| Short | Under `RAG_CHUNK_MIN_TOKENS`: merged into the previous section, else absorbs the next, only within the same `##` parent and only if within the max |
| Content | Starts with the heading line; `embeddingText()` prefixes the heading path; `hash` is sha256 of `embeddingText()` |
| Tokens | Estimated as characters / 4 |

13.x baseline (800 max, 60 min): 2216 chunks, ~800K tokens, median 297, none over the max.

## Ingestion

| Piece | Role |
|---|---|
| `App\Ingestion\DocFileIndexer` | Chunks one page, compares with stored rows by anchor + hash + `embedding_model`, embeds only new/changed chunks, upserts, deletes anchors gone from the page |
| `App\Jobs\IngestDocFile` | Queued, batchable wrapper; releases with backoff (15s up to 300s, 8 tries) on rate limit, overloaded or connection errors; other errors fail |
| `App\Ingestion\IngestStats` | Per-batch cache counters (`embedded`, `reused`, `unchanged`, `deleted`) for reporting |

- Identical content under a new anchor reuses the stored vector (`reused`), so section moves cost nothing
- Upserts go through `DB::table()` with the vector as a JSON array literal, which pgvector parses directly

## Data model

`doc_chunks`: one row per docs section (or part of an oversized section).

| Column | Notes |
|---|---|
| `version`, `file`, `anchor` | Unique together; `version` indexed for filtering |
| `heading`, `heading_path`, `url` | Display and citation; `url` links to the laravel.com section |
| `content`, `token_count` | Section text and approximate size |
| `hash` | sha256 of content; indexed with `version`, `file` for incremental re-ingest |
| `embedding_model` | Model that produced the vector |
| `embedding` | `vector(RAG_EMBEDDING_DIMENSIONS)` with HNSW `vector_cosine_ops` index on Postgres; JSON on SQLite |

## Testing

| Suite | Database | Run |
|---|---|---|
| `tests/Unit`, `tests/Feature` | SQLite in memory | `make test` |
| `tests/Pgsql` (group `pgsql`) | Postgres `laravel_testing` | `make test-pgsql`; skipped under `make test` |

Migrations check the driver and skip pgvector-only columns/indexes on SQLite. `tests/TestCase.php` binds `FakeEmbedder` and calls `Embeddings::fake()->preventStrayEmbeddings()`, so no test can reach a real API.

## Key files

| Path | Purpose |
|---|---|
| `Dockerfile` | FrankenPHP image with pgsql/redis/pcntl extensions, Composer, Node 24, `git`; runs as UID 1000 |
| `docker-compose.yml` | `app`, `pgsql`, `redis` with healthchecks |
| `docker/pgsql/init.sql` | Creates `laravel_testing` and enables the `vector` extension in both databases |
| `app/Contracts/Embedder.php`, `app/Services/Embeddings/` | Embedding contract, AI SDK implementation, deterministic fake |
| `config/rag.php` | Embedding/chat provider and model, chunk limits, top k, similarity threshold, retrieval mode, docs source |
| `Makefile` | All developer commands; `make help` lists them |
| `pint.json` | Laravel preset plus `declare_strict_types` on every PHP file |
| `.husky/` | `commit-msg` (lowercase conventional commits, no scopes) and `pre-commit` (Pint, osv-scanner on `composer.lock`); hook bodies are POSIX `sh` |
| `app/Providers/AppServiceProvider.php` | Swaps the default `artisan serve` dev command for `frankenphp php-server` |

## Decisions

| Decision | Why |
|---|---|
| PostgreSQL over MySQL | Laravel 13's native `vector` column and `whereVectorSimilarTo` target pgvector; Postgres full-text search (`tsvector`) enables hybrid search in one database |
| Postgres over MongoDB Atlas | Atlas auto-embedding hides the embedding step this project exists to teach; pgvector keeps embed, store and query explicit |
| `php artisan dev` serves through FrankenPHP | Keeps the single dev entry point (server, queue, logs, Vite) while using FrankenPHP instead of the PHP built-in server |
| Container runs as UID 1000 | Files created in the bind mount stay owned by the host user |
| SQLite in memory for tests | Fast and isolated; Postgres-only features (vector, `tsvector`) are guarded by driver and covered by a separate `pgsql` test group against `laravel_testing` |
| Voyage AI embeddings, Claude answers, OpenAI optional | Anthropic has no embeddings API; Voyage is its recommended partner. OpenAI can replace either via `.env` |
| Docs cloned to `storage/app/docs/{version}` | Source Markdown from `laravel/docs` branches; gitignored |
