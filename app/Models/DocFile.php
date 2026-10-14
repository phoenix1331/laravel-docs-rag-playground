<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The last successfully ingested state of one docs page, used to skip pages that have not changed.
 */
#[Fillable(['version', 'file', 'blob_hash', 'signature', 'chunk_count', 'ingested_at'])]
class DocFile extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_count' => 'integer',
            'ingested_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<DocFile>  $query
     */
    #[Scope]
    protected function forVersion(Builder $query, string $version): void
    {
        $query->where('version', $version);
    }
}
