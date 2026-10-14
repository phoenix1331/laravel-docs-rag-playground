<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | The provider and model that turn docs sections and questions into
    | vectors. Any provider in config/ai.php with embedding support works,
    | e.g. "voyageai" (voyage-4, 1024) or "openai" (text-embedding-3-small,
    | 1536). Changing the model or dimensions means re-running the
    | migrations and "docs:ingest --fresh", as vectors are not comparable
    | across models.
    |
    */

    'embedding' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'voyageai'),
        'model' => env('RAG_EMBEDDING_MODEL', 'voyage-4'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1024),
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 64),
    ],

    /*
    |--------------------------------------------------------------------------
    | Answer generation
    |--------------------------------------------------------------------------
    |
    | The provider and model that write grounded answers from the retrieved
    | sections, e.g. "anthropic" (claude-opus-5-5) or "openai".
    |
    */

    'chat' => [
        'provider' => env('RAG_CHAT_PROVIDER', 'anthropic'),
        'model' => env('RAG_CHAT_MODEL', 'claude-opus-5-5'),
        'max_tokens' => (int) env('RAG_CHAT_MAX_TOKENS', 4096),
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
