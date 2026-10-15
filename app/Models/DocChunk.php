<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'version',
    'file',
    'heading',
    'heading_path',
    'anchor',
    'url',
    'content',
    'token_count',
    'hash',
    'embedding_model',
    'embedding',
])]
#[Hidden(['embedding', 'search_vector'])]
class DocChunk extends Model
{
    /** @use HasFactory<DocChunkFactory> */
    use HasFactory;

    /**
     * Postgres text search configuration for the generated search_vector column. Full-text queries
     * must use the same one, or stemmed words will not match.
     */
    public const string TEXT_SEARCH_CONFIG = 'english';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'token_count' => 'integer',
        ];
    }

    /**
     * Limit the query to chunks from a single docs version.
     *
     * @param  Builder<DocChunk>  $query
     */
    #[Scope]
    protected function forVersion(Builder $query, string $version): void
    {
        $query->where('version', $version);
    }
}
