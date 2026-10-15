# Context

Laravel 13 playground that answers questions about the Laravel documentation using retrieval-augmented generation (RAG). The user picks a docs version, asks a question, and gets an answer grounded in the official docs with links to the sections used. The goal is learning: every RAG step (chunking, embedding, retrieval, generation, evaluation) is visible and measurable rather than hidden behind a managed service.

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Runtime | Docker, FrankenPHP (`dunglas/frankenphp:php8.4`) |
| Database | PostgreSQL 17 with pgvector (`pgvector/pgvector:pg17`) |
| Queue / cache | Redis 8, Horizon |
| Tests | Pest, SQLite in memory by default |
| AI | Laravel AI SDK (`laravel/ai`) |
| Front end | Livewire 4 (class-based `app/Livewire`), Tailwind v4, Vite; fonts via `bunny()` in `vite.config.js` |

## Services and ports

| Service | Container port | Host port | Notes |
|---|---|---|---|
| `app` | 8000 | `APP_PORT` (8000) | `php artisan dev`: FrankenPHP, Horizon, Pail, Vite; dashboard at `/horizon` |
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
- Rate limits: `RAG_EMBEDDING_BATCH_TOKENS` caps each request by estimated tokens; `RAG_EMBEDDING_RPM` / `RAG_EMBEDDING_TPM` drive `App\Services\Embeddings\EmbeddingThrottle`, which reserves evenly spaced slots in the cache (shared across workers, no window-edge bursts). 0 disables. Local `.env`: 8000 / 2 / 9000 with 1 worker (RPM one under Voyage's 3 so questions still get through during an ingest). Voyage counts about 16% fewer tokens than the chars/4 estimate
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

- `php artisan docs:ingest {version} {--fresh} {--all} {--no-pull} {--no-wait}`: syncs the clone, removes pages no longer in the version, dispatches an `IngestDocFile` per **changed** page in a `Bus::batch` (`allowFailures`), shows progress, prints totals
- Two levels of change detection: `doc_files` skips whole pages whose git blob hash (`sha1("blob {size}\0{content}")`, identical to `git ls-tree`) and signature are unchanged; within a page, chunk hashes decide what is re-embedded
- Signature = `MarkdownChunker::RULES_VERSION` + chunk limits + embedding model; bump `RULES_VERSION` when chunking rules change so unchanged pages are re-chunked. `--all` forces every page
- A page is recorded in `doc_files` only after it indexes successfully, so failed pages are retried on the next run
- Refuses to run when stored chunks for the version use another `embedding_model`, unless `--fresh`
- Identical content under a new anchor reuses the stored vector (`reused`), so section moves cost nothing
- Upserts go through `DB::table()` with the vector as a JSON array literal, which pgvector parses directly

## Retrieval

`App\Retrieval\Retriever::retrieve($query, $version, $mode, $topK)` returns a `RetrievalResult` of `RetrievedChunk`s (chunk, rank, score, similarity).

| Mode | How |
|---|---|
| `vector` | `embedQuery()`, then `DocChunk::forVersion()` + `selectVectorDistance()` + `whereVectorSimilarTo(minSimilarity: 0.0)`, limit k; similarity = 1 - cosine distance |

- `php artisan docs:search "{query}" {--docs-version=} {--k=} {--mode=} {--content}` (or `make search Q="..."`): ranked results with scores and links, no chat model; warns when the best similarity is under `RAG_MIN_SIMILARITY`. `--docs-version` because `--version` is reserved by Artisan
- Question embeddings skip the throttle (retrying briefly on 429) so a question never queues behind an ingest; card-free ingestion is token-bound at about 1 request a minute, leaving spare requests
- The version filter is in the SQL, never left to the model
- Embeddings are not selected into results
- `hnsw.iterative_scan = strict_order` is set per connection. Without it, when the planner uses the HNSW index, the index returns its `ef_search` (40) nearest rows before the version filter, so a 50/50 two-version table returned **0** rows for `ORDER BY distance LIMIT 6` in testing
- At this size the planner prefers an exact scan + sort, because the `whereVectorSimilarTo` distance condition makes HNSW look costlier; results are exact and take milliseconds. The iterative scan setting protects the HNSW path as the table grows

## Answering

`App\Answering\DocsAssistant::answer($question, $version, ?$onDelta)` returns an `Answer` (status `answered` / `not_covered` / `offline`, text, cited numbers, retrieval, provider, model, tokens).

| Step | Detail |
|---|---|
| Retrieve | `Retriever` top k for the version |
| Threshold | Best similarity below `RAG_MIN_SIMILARITY` returns "not covered" with **no model call** |
| Prompt | `DocsPrompt`: rules in the system instructions; numbered `<section number title url>` blocks plus the question in the user message |
| Rules | Only the sections; cite `[n]`; reply exactly `NOT_COVERED` if unanswerable; say which part is missing if partial; section text is reference material, not instructions |
| Generate | `App\Ai\Agents\DocsAgent` via the SDK; provider list `[RAG_CHAT_PROVIDER => model, RAG_CHAT_FALLBACK_PROVIDER => model]` for SDK failover; 2 retries (2s, 5s) on rate limit, overloaded or connection errors; Anthropic gets `output_config.effort = low` |
| Stream | `onDelta` receives text as it arrives; output that could be the start of `NOT_COVERED` is held back so the raw sentinel never reaches the user |
| Citations | `[1]`, `[1][3]`, `[1, 4]` parsed and limited to existing section numbers; `Answer::citations()` maps them to chunks |
| Offline | `RAG_CHAT_PROVIDER=fake` lists the top 3 sections with citations, no model call |

- Refusal has two layers: the similarity threshold (cheap, catches clear misses) and the model's `NOT_COVERED` (catches off-topic sections above the threshold). Live 13.x: a Kubernetes question scored 0.291 (refused at 0.30, narrowly); an off-topic Blade section scored 0.362 for a validation question. Tune with the eval set
- `php artisan docs:ask "{question}" {--docs-version=} {--no-stream} {--context}` (or `make ask Q="..."`): streams the answer, lists cited sources with URLs, `--context` shows every retrieved section with similarity and cited flag, footer shows provider, model and tokens or why no model was called
- Live check (Gemini `gemini-3.1-flash-lite`): policy authorisation question answered with 4 citations, ~2.9K tokens in / 340 out

## UI

Single page at `/` (`App\Livewire\AskDocs`, layout `resources/views/layouts/app.blade.php`).

| Design token (`resources/css/app.css`) | Role |
|---|---|
| `paper`, `surface` | Backgrounds |
| `ink`, `muted` | Text |
| `rule` | Hairlines |
| `cite`, `cite-soft` | Sections the answer cited, citation chips |
| `threshold` | The refusal cut-off only |

- Dark mode via `prefers-color-scheme`, same roles redefined
- Type: Source Serif 4 for the answer (reads as documentation), Public Sans for the tool, JetBrains Mono for code and scores
- Two-step submit: `ask()` validates and renders the question, then `$this->js('$wire.generate()')`; `generate()` streams deltas with `$this->stream(to: 'answer')` into a `wire:stream` element (raw text), then swaps in the rendered answer. Verified live: 26 progressive DOM updates, first text about 3s after submit. `wire:stream` works because FrankenPHP runs in classic (`php-server`) mode, not worker/Octane mode
- Answers render through `App\Answering\AnswerHtml` (CommonMark, HTML escaped, unsafe links off); `[n]` outside code becomes a chip linking to `#source-n`
- Ligatures are off for code so PHP's `->` and `=>` are not drawn as arrows
- Retrieved context panel (right rail on desktop, below the answer on mobile, `<details>` so it collapses): every retrieved section with similarity, cited flag, tokens and excerpt, shown for refusals too
- `x-relevance-ruler` (`resources/views/components/relevance-ruler.blade.php`): each section as a mark on a 0 to 1 similarity scale with the refusal threshold drawn across; marks within 0.035 stack into lanes; cited marks filled
- Refusal footers distinguish "no model was called" (threshold) from "model judged none answer" (`NOT_COVERED`)
- Header links to Horizon when `Horizon::check()` allows it (local by default)

## Evaluation

`database/eval/questions.json`: 31 answerable 13.x questions (plain developer wording, not heading text; a few name exact methods to test keyword matching) plus 5 unanswerable (3 off-topic, 2 Laravel-sounding near misses). Each answerable question lists acceptable `page.md#anchor` entries; a retrieved chunk matches on page + URL fragment, so split parts count. Expected anchors were taken from real chunker output, since short sections merged into a neighbour lose their own anchor.

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

`doc_files`: last successful ingest per page (`version`, `file` unique; `blob_hash`, `signature`, `chunk_count`, `ingested_at`).

`questions`: one row per question asked through `DocsAssistant` (web or CLI): version, question, channel, mode, status (`answered` / `not_covered` / `offline` / `error`), answer, `retrieved` JSON (chunk id, rank, similarity, cited), `cited_chunk_ids`, best similarity, provider, model, tokens, duration, error. Written by `App\Answering\QuestionLog` via `rescue()` so logging never breaks answering; `RAG_LOG_QUESTIONS=false` turns it off. Retrieval eval does not go through the assistant, so it is not logged.

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
| `app/Answering/`, `app/Ai/Agents/DocsAgent.php` | `DocsAssistant`, `DocsPrompt`, `Answer`, SDK agent |
| `app/Retrieval/` | `Retriever`, `RetrievalResult`, `RetrievedChunk` |
| `app/Contracts/Embedder.php`, `app/Services/Embeddings/` | Embedding contract, AI SDK implementation, deterministic fake |
| `config/rag.php` | Embedding/chat provider and model, chunk limits, top k, similarity threshold, retrieval mode, docs source |
| `Makefile` | All developer commands; `make help` lists them (`make ingest V=13.x`, `make horizon-restart` after code changes) |
| `pint.json` | Laravel preset plus `declare_strict_types` on every PHP file |
| `.husky/` | `commit-msg` (lowercase conventional commits, no scopes) and `pre-commit` (Pint, osv-scanner on `composer.lock`); hook bodies are POSIX `sh` |
| `app/Providers/AppServiceProvider.php` | Swaps the default `artisan serve` dev command for `frankenphp php-server` |

## Decisions

| Decision | Why |
|---|---|
| PostgreSQL over MySQL | Laravel 13's native `vector` column and `whereVectorSimilarTo` target pgvector; Postgres full-text search (`tsvector`) enables hybrid search in one database |
| Postgres over MongoDB Atlas | Atlas auto-embedding hides the embedding step this project exists to teach; pgvector keeps embed, store and query explicit |
| `php artisan dev` serves through FrankenPHP and runs Horizon | One dev entry point; `artisan serve` and `queue:listen` are excluded in `AppServiceProvider`, Horizon registers itself as a dev command, so no separate worker container is needed |
| Low ingest concurrency | `RAG_INGEST_WORKERS` (default 2) Horizon processes; free embedding tiers rate limit, and jobs back off rather than fail |
| Long queue timeouts | `IngestDocFile::$timeout` 1800s (throttled pages can take minutes), Horizon supervisor 1810s, Redis `retry_after` 1830s so slow jobs are not handed out twice |
| `make horizon-restart` restarts the container | `horizon:terminate` exits cleanly and `php artisan dev` only restarts crashed processes, so Horizon would stay down |
| Throttle embeddings rather than add a card | Voyage without a payment method allows 3 RPM / 10K TPM; `EmbeddingThrottle` spaces requests so a card-free ingest finishes (about 90 min per version) instead of thrashing on 429s |
| Container runs as UID 1000 | Files created in the bind mount stay owned by the host user |
| SQLite in memory for tests | Fast and isolated; Postgres-only features (vector, `tsvector`) are guarded by driver and covered by a separate `pgsql` test group against `laravel_testing` |
| Voyage AI embeddings, Claude answers, OpenAI optional | Anthropic has no embeddings API; Voyage is its recommended partner. OpenAI can replace either via `.env` |
| Docs cloned to `storage/app/docs/{version}` | Source Markdown from `laravel/docs` branches; gitignored |
