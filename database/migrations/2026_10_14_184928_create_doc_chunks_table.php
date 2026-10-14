<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $usesPgvector = Schema::getConnection()->getDriverName() === 'pgsql';

        if ($usesPgvector) {
            Schema::ensureVectorExtensionExists();
        }

        Schema::create('doc_chunks', function (Blueprint $table) use ($usesPgvector) {
            $table->id();
            $table->string('version')->index();
            $table->string('file');
            $table->string('heading');
            $table->text('heading_path');
            $table->string('anchor');
            $table->string('url');
            $table->text('content');
            $table->unsignedInteger('token_count');
            $table->char('hash', 64);
            $table->string('embedding_model');
            $table->timestamps();

            $table->unique(['version', 'file', 'anchor']);
            $table->index(['version', 'file', 'hash']);

            // SQLite (used by the default test suite) has no vector type, so the embedding is stored as JSON there.
            if (! $usesPgvector) {
                $table->json('embedding');

                return;
            }

            $table->vector('embedding', dimensions: config('rag.embedding.dimensions'))->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_chunks');
    }
};
