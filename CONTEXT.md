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

| Role | Default | Alternative | Config |
|---|---|---|---|
| Embeddings | Voyage AI `voyage-4`, 1024 dims | OpenAI `text-embedding-3-small`, 1536 dims | `RAG_EMBEDDING_PROVIDER`, `RAG_EMBEDDING_MODEL`, `RAG_EMBEDDING_DIMENSIONS` |
| Answers | Anthropic `claude-opus-5-5` | OpenAI chat model | `RAG_CHAT_PROVIDER`, `RAG_CHAT_MODEL` |

- Switching is `.env` only; any embedding-capable provider in `config/ai.php` works
- `RAG_EMBEDDING_PROVIDER=fake` uses the offline `FakeEmbedder` (word-hashing vectors) for local runs without an API key
- Embedding goes through `App\Contracts\Embedder` (`embedDocuments()`, `embedQuery()`); `AiSdkEmbedder` wraps `Laravel\Ai\Embeddings`, batches by `RAG_EMBEDDING_BATCH_SIZE`, checks counts and dimensions, and sends Voyage `input_type` document/query
- Changing embedding model or dimensions needs `make fresh` then a fresh ingest: the vector column is sized from config and vectors from different models are not comparable
- `config/ai.php` defaults follow the same `RAG_*_PROVIDER` env keys; `config/rag.php` holds model, chunking, retrieval and docs-source settings

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
