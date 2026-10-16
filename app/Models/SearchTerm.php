<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $term
 * @property string $term_sha256
 * @property string $model
 * @property string $embedding
 * @property Carbon|null $embedded_at
 */
#[Fillable([
    'term',
    'term_sha256',
    'model',
    'embedded_at',
])]
class SearchTerm extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedded_at' => 'datetime',
        ];
    }
}
