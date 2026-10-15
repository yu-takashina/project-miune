<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $sitemap_page_id
 * @property string $embedding
 * @property string $article_text_sha256
 * @property Carbon|null $embedded_at
 */
#[Fillable([
    'sitemap_page_id',
    'article_text_sha256',
    'embedded_at',
])]
class SitemapPageEmbedding extends Model
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

    /**
     * @return BelongsTo<SitemapPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitemapPage::class, 'sitemap_page_id');
    }
}
