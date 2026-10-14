<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One question asked through the app: what was retrieved, what was cited, the outcome and its cost.
 */
#[Fillable([
    'version',
    'question',
    'channel',
    'mode',
    'status',
    'answer',
    'retrieved',
    'cited_chunk_ids',
    'best_similarity',
    'provider',
    'model',
    'input_tokens',
    'output_tokens',
    'duration_ms',
    'error',
])]
class Question extends Model
{
    public const string ERROR = 'error';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retrieved' => 'array',
            'cited_chunk_ids' => 'array',
            'best_similarity' => 'float',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
