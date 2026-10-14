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
        Schema::create('doc_files', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->string('file');
            $table->char('blob_hash', 40);
            $table->char('signature', 40);
            $table->unsignedInteger('chunk_count');
            $table->timestamp('ingested_at');
            $table->timestamps();

            $table->unique(['version', 'file']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_files');
    }
};
