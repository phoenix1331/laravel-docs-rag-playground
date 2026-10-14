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
| Front end | Vite + Tailwind (Livewire to follow) |

## Services and ports

| Service | Container port | Host port | Notes |
|---|---|---|---|
| `app` | 8000 | `APP_PORT` (8000) | FrankenPHP via `php artisan dev` |
| `app` (Vite) | 5173 | `VITE_PORT` (5173) | HMR host is `localhost` |
| `pgsql` | 5432 | `FORWARD_DB_PORT` (5433) | 5432 is taken on the dev host |
| `redis` | 6379 | `FORWARD_REDIS_PORT` (6379) | Queue and cache store |

Inside the network the app reaches `pgsql:5432` and `redis:6379`.

## Key files

| Path | Purpose |
|---|---|
| `Dockerfile` | FrankenPHP image with pgsql/redis/pcntl extensions, Composer, Node 24, `git`; runs as UID 1000 |
| `docker-compose.yml` | `app`, `pgsql`, `redis` with healthchecks |
| `docker/pgsql/init.sql` | Creates `laravel_testing` and enables the `vector` extension in both databases |
| `Makefile` | All developer commands; `make help` lists them |
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
