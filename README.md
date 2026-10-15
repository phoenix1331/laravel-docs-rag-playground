# Laravel Docs RAG Playground

<img width="3098" height="1652" alt="Screenshot 2026-10-14 213914" src="https://github.com/user-attachments/assets/3213ae07-c857-4523-98e2-b67dd2568eb3" />


Ask questions about the Laravel documentation and get answers written only from the official docs, with citations to the exact sections, built as a fully visible retrieval-augmented generation (RAG) pipeline in Laravel 13 and PostgreSQL.

## What this demonstrates

Most RAG tutorials hand the hard parts to a managed service. This project builds every step in plain Laravel so you can see it, change it and measure it:

- **Structure-aware chunking**: docs pages split on their own headings, code blocks kept whole, anchors preserved for citations
- **Embeddings and vector search** in Postgres with pgvector, using Laravel 13's native `vector` column and `whereVectorSimilarTo`
- **Grounded, cited answers** that stream into a Livewire page, with a two-layer refusal for questions the docs do not cover
- **A retrieval eval** (`make eval`) that scores search with recall@k and MRR, so changes are judged by numbers rather than by eye
- **Hybrid search** (vector plus Postgres full-text, merged with reciprocal rank fusion), and the measured result that it lost on this data
- **Incremental, rate-limited ingestion** on queues, so a free-tier embedding account can index the whole docs site

The explainers are on the project page, **[https://phoenix1331.github.io/laravel-docs-rag-playground/](https://phoenix1331.github.io/laravel-docs-rag-playground/)** (source: [`docs/index.html`](docs/index.html)):

| Section | Covers |
|---|---|
| [How RAG works here](https://phoenix1331.github.io/laravel-docs-rag-playground/#how-it-works) | Docs page to cited answer, step by step |
| [Evaluating retrieval](https://phoenix1331.github.io/laravel-docs-rag-playground/#evaluation) | What eval is, recall@k and MRR with worked examples, results per stage |
| [Hybrid search](https://phoenix1331.github.io/laravel-docs-rag-playground/#hybrid) | Keyword plus vector search, RRF, and why hybrid lost here |
| [Providers and costs](https://phoenix1331.github.io/laravel-docs-rag-playground/#providers) | Voyage and Claude defaults, switching to OpenAI, free tiers, throttling, why Postgres |
| [Adapting this for private documents](https://phoenix1331.github.io/laravel-docs-rag-playground/#private) | Hosting, permissions, deletion, redaction, prompt injection, audit |

### Results

<img width="2316" height="1006" alt="Screenshot 2026-10-15 112859" src="https://github.com/user-attachments/assets/b2cc1fa4-0182-4331-8ae5-7128d4b7c4d1" />

Laravel 13.x docs, 2,215 sections, 31 answerable questions:

| Search | Recall@1 | Recall@5 | MRR |
|---|---|---|---|
| Word hashing (offline, no meaning) | | 32.3% | 0.255 |
| Vector, Voyage `voyage-4` | 67.7% | 96.8% | 0.803 |
| Hybrid, vector plus keyword | 58.1% | 93.5% | 0.744 |

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Database | PostgreSQL 17 with pgvector |
| Runtime | Docker, FrankenPHP (`dunglas/frankenphp:php8.4`) |
| Queue and cache | Redis 8, Laravel Horizon |
| AI | Laravel AI SDK (`laravel/ai`): Voyage AI embeddings, Anthropic Claude answers by default |
| Front end | Livewire 4, Tailwind CSS 4, Vite |
| Tests | Pest; SQLite in memory, plus a Postgres-only group |

## Getting started

### Prerequisites

- Docker with Docker Compose, and `make`
- PHP 8.4 and Node.js on the host, for the git hooks (Pint runs on commit)
- [`osv-scanner`](https://github.com/google/osv-scanner) for the pre-commit security audit: `go install github.com/google/osv-scanner/cmd/osv-scanner@latest`
- Run `npm install` once on the host to activate the Husky git hooks
- API keys: a [Voyage AI](https://www.voyageai.com) key for embeddings and an [Anthropic](https://console.anthropic.com) key for answers. To try it with no keys at all, set `RAG_EMBEDDING_PROVIDER=fake` and `RAG_CHAT_PROVIDER=fake` (offline, much weaker retrieval)
- Ports 8000, 5173, 5433 and 6379 free on the host (change them in `.env` if not)

Claude Code MCP servers (`.mcp.json`), only if you use Claude Code on this repo:

- GitHub MCP authenticates with `gh auth token`: run `gh auth login` once per machine
- MySQL MCP reads `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE` (default `127.0.0.1:3306`, user `root`). This project uses Postgres, so the MySQL server is unused here
- Playwright and Context7 need only `npx`

### Steps

1. `make install` creates `.env` from `.env.example`, builds the image, installs PHP and JS dependencies, generates the app key, starts the containers and migrates
2. Add `VOYAGEAI_API_KEY` and `ANTHROPIC_API_KEY` to `.env`. On a card-free Voyage account also set the [throttle values](https://phoenix1331.github.io/laravel-docs-rag-playground/#providers)
3. `make horizon-restart` so the queue workers pick up the new `.env`
4. `make ingest V=13.x` clones the docs and indexes them (minutes with a paid key, about 90 minutes card-free; progress is also visible at http://localhost:8000/horizon)
5. Open http://localhost:8000 and ask a question, or use `make ask Q="how do I soft delete a model"`

Other useful targets:

| Command | Does |
|---|---|
| `make search Q="..."` | Show what retrieval returns, with scores, without calling a chat model |
| `make eval` | Score retrieval against the eval set (`ARGS="--mode=hybrid"`, `ARGS="--k=10"`) |
| `make test` / `make test-pgsql` | Run the test suite on SQLite / the Postgres-only tests |
| `make lint` | Fix code style with Pint |
| `make help` | List every target |

## Environment variables

Beyond the standard Laravel keys:

| Key | Default | Description |
|---|---|---|
| `APP_PORT` | `8000` | Host port for the app |
| `VITE_PORT` | `5173` | Host port for Vite hot reload |
| `FORWARD_DB_PORT` | `5433` | Host port for Postgres (5432 is often taken) |
| `FORWARD_REDIS_PORT` | `6379` | Host port for Redis |
| `VOYAGEAI_API_KEY`, `ANTHROPIC_API_KEY` | | Keys for the default providers; `OPENAI_API_KEY`, `GEMINI_API_KEY`, `GROQ_API_KEY` for others |
| `RAG_EMBEDDING_PROVIDER` | `voyageai` | Embedding provider, or `fake` for offline |
| `RAG_EMBEDDING_MODEL` | provider default | Embedding model |
| `RAG_EMBEDDING_DIMENSIONS` | `1024` | Vector size; at most 2000. Changing it needs `make fresh` and a re-ingest |
| `RAG_EMBEDDING_BATCH_TOKENS` | `0` | Estimated tokens per embedding request (0 = no cap) |
| `RAG_EMBEDDING_RPM`, `RAG_EMBEDDING_TPM` | `0` | Embedding requests and tokens per minute for the throttle (0 = off) |
| `RAG_CHAT_PROVIDER` | `anthropic` | Answer provider, or `fake` for offline |
| `RAG_CHAT_MODEL` | provider default | Answer model |
| `RAG_CHAT_FALLBACK_PROVIDER`, `RAG_CHAT_FALLBACK_MODEL` | | Optional provider to fail over to |
| `RAG_RETRIEVAL_MODE` | `vector` | `vector` or `hybrid` |
| `RAG_TOP_K` | `6` | Sections given to the model per question |
| `RAG_MIN_SIMILARITY` | `0.3` | Below this best similarity, reply "not covered" without calling the model |
| `RAG_RRF_K` | `60` | Reciprocal rank fusion constant for hybrid mode |
| `RAG_LOG_QUESTIONS` | `true` | Store each question, retrieval and token usage |
| `RAG_INGEST_WORKERS` | `2` | Horizon worker processes for ingestion |
| `RAG_DEFAULT_VERSION` | `13.x` | Docs version selected by default |

## Endpoints

| Route | Purpose |
|---|---|
| `GET /` | The ask page: version selector, streamed answer, citations, retrieved context panel |
| `GET /up` | Health check, used by the container healthcheck |
| `GET /horizon` | Queue dashboard for ingestion (local only) |

`request.http` has these for VS Code REST Client and JetBrains HTTP Client. Answers are Livewire actions rather than a JSON API; the CLI equivalents are `docs:ask`, `docs:search` and `rag:eval`.

## Architecture notes

- **The version filter is in SQL, not the prompt.** A 13.x answer cannot be built from 12.x sections, whatever the model does. The same pattern is how permissions would work for [private documents](https://phoenix1331.github.io/laravel-docs-rag-playground/#private).
- **Refusal has two layers.** A similarity threshold refuses clear misses with no model call; the model's `NOT_COVERED` reply catches the rest. The eval showed unanswerable questions score as high as real ones, so the threshold alone cannot do it.
- **Chunk by structure, embed with context.** Sections follow the docs' headings, and each is embedded with its heading path (`Eloquent > Deleting Models > Soft Deleting`), so short sections are still findable.
- **Ingest is incremental at two levels.** Unchanged pages are skipped by git blob hash; within a changed page only changed sections are re-embedded, and moved sections reuse their vectors.
- **Throttle rather than retry.** Embedding requests reserve evenly spaced slots shared by every worker, so a card-free account indexes the whole site instead of thrashing on rate-limit errors. Questions skip the throttle, so the app stays usable during an ingest.
- **Measure before switching.** Hybrid search is the usual recommendation; here it scored lower, so vector stays the default. The [eval](https://phoenix1331.github.io/laravel-docs-rag-playground/#evaluation) is what made that a decision rather than a guess.
- **Postgres for everything.** Vectors, full-text search, metadata and filters in one database and one query, instead of a separate vector store. See [why](https://phoenix1331.github.io/laravel-docs-rag-playground/#providers).

Orientation for contributors, including every design decision and its reason, is in [`CONTEXT.md`](CONTEXT.md).
