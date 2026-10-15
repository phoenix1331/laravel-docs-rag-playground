<?php

declare(strict_types=1);

use App\Models\DocChunk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a full-text search column kept in step with each row by Postgres itself.
     *
     * The heading path (page title, parent headings and the section heading) is weighted A and the
     * content B, so a query word in a heading outranks the same word in body text. SQLite has no
     * tsvector, so the column only exists on Postgres.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $config = DocChunk::TEXT_SEARCH_CONFIG;

        DB::statement(<<<SQL
            alter table doc_chunks add column search_vector tsvector generated always as (
                setweight(to_tsvector('{$config}', coalesce(heading_path, '')), 'A')
                || setweight(to_tsvector('{$config}', coalesce(content, '')), 'B')
            ) stored
        SQL);

        DB::statement('create index doc_chunks_search_vector_index on doc_chunks using gin (search_vector)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop index if exists doc_chunks_search_vector_index');
        DB::statement('alter table doc_chunks drop column if exists search_vector');
    }
};
