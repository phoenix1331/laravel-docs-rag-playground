<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | The provider and model that turn docs sections and questions into
    | vectors. Any embedding provider in config/ai.php works, plus "fake"
    | for offline use. A null model uses the provider's default. Dimensions
    | size the vector column and must be 2000 or less for the HNSW index.
    | Changing any of these means re-running the migrations and
    | "docs:ingest --fresh", as vectors are not comparable across models.
    |
    */

    'embedding' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'voyageai'),
        'model' => env('RAG_EMBEDDING_MODEL') ?: null,
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1024),
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 64),

        // Free tiers limit requests and tokens per minute (e.g. Voyage without a card: 3 RPM, 10K TPM).
        // 0 disables a limit. Tokens are estimated, so leave headroom below the provider's real limit.
        'batch_tokens' => (int) env('RAG_EMBEDDING_BATCH_TOKENS', 0),
        'requests_per_minute' => (int) env('RAG_EMBEDDING_RPM', 0),
        'tokens_per_minute' => (int) env('RAG_EMBEDDING_TPM', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Answer generation
    |--------------------------------------------------------------------------
    |
    | The provider and model that write grounded answers from the retrieved
    | sections. Any text provider in config/ai.php works, plus "fake" for an
    | offline templated answer. A null model uses the provider's default.
    |
    */

    'chat' => [
        'provider' => env('RAG_CHAT_PROVIDER', 'anthropic'),
        'model' => env('RAG_CHAT_MODEL') ?: null,
        'max_tokens' => (int) env('RAG_CHAT_MAX_TOKENS', 4096),

        // Optional second provider the SDK fails over to when the first is rate limited or down.
        'fallback_provider' => env('RAG_CHAT_FALLBACK_PROVIDER') ?: null,
        'fallback_model' => env('RAG_CHAT_FALLBACK_MODEL') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Sections over "max_tokens" are split on paragraph boundaries; sections
    | under "min_tokens" are merged into a neighbour. Token counts are
    | approximate (roughly four characters per token).
    |
    */

    'chunking' => [
        'max_tokens' => (int) env('RAG_CHUNK_MAX_TOKENS', 800),
        'min_tokens' => (int) env('RAG_CHUNK_MIN_TOKENS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | "mode" is "vector" or "hybrid". "min_similarity" is the cosine
    | similarity a chunk must reach for the model to be called at all;
    | below it the question is treated as not covered by the docs.
    | "rrf_k" is the reciprocal rank fusion constant for hybrid mode.
    |
    */

    'retrieval' => [
        'mode' => env('RAG_RETRIEVAL_MODE', 'vector'),
        'top_k' => (int) env('RAG_TOP_K', 6),
        'min_similarity' => (float) env('RAG_MIN_SIMILARITY', 0.3),
        'rrf_k' => (int) env('RAG_RRF_K', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Docs source
    |--------------------------------------------------------------------------
    */

    'docs' => [
        'repository' => env('RAG_DOCS_REPOSITORY', 'https://github.com/laravel/docs.git'),
        'path' => storage_path('app/docs'),
        'base_url' => 'https://laravel.com/docs',
        'versions' => ['13.x', '12.x'],
        'default_version' => env('RAG_DEFAULT_VERSION', '13.x'),
    ],

];
