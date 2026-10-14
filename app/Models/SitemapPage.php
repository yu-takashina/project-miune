<?php

namespace App\Models;

use App\Enums\ExtractionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $sitemap_id
 * @property string $url
 * @property string $url_sha256
 * @property bool $searchable
 * @property Carbon|null $unlisted_at
 * @property int $absence_count
 * @property int|null $http_status
 * @property int|null $response_bytes
 * @property string|null $body
 * @property string|null $body_sha256
 * @property string|null $article_text 採用した本文。抽出器ごとの文字列は保存しない。
 * @property string|null $article_text_sha256 本文が空のときは null。
 * @property ExtractionStatus|null $extraction_status 本文を採用できたとき、未抽出のときは null。
 * @property Carbon|null $extracted_at
 * @property Carbon|null $crawled_at
 * @property string|null $error_message
 */
#[Fillable([
    'sitemap_id',
    'url',
    'searchable',
    'unlisted_at',
    'absence_count',
    'http_status',
    'response_bytes',
    'body',
    'body_sha256',
    'article_text',
    'extraction_status',
    'extracted_at',
    'crawled_at',
    'error_message',
])]
class SitemapPage extends Model
{
    protected static function booted(): void
    {
        static::saving(function (SitemapPage $page): void {
            $page->url_sha256 = hash('sha256', $page->url);
            $page->article_text_sha256 = is_string($page->article_text) && $page->article_text !== ''
                ? hash('sha256', $page->article_text)
                : null;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'searchable' => 'boolean',
            'unlisted_at' => 'datetime',
            'absence_count' => 'integer',
            'extraction_status' => ExtractionStatus::class,
            'extracted_at' => 'datetime',
            'crawled_at' => 'datetime',
        ];
    }

    /**
     * 採用して保存した本文。
     */
    public function articleText(): ?string
    {
        return $this->article_text;
    }

    /**
     * サイト内検索に出すページ。掲載中で、検索対象が ON、本文があるもの。
     *
     * @param  Builder<SitemapPage>  $query
     */
    public function scopeForSearch(Builder $query): void
    {
        $query->where('searchable', true)
            ->whereNull('unlisted_at')
            ->whereNotNull('body');
    }

    /**
     * @return BelongsTo<Sitemap, $this>
     */
    public function sitemap(): BelongsTo
    {
        return $this->belongsTo(Sitemap::class);
    }

    public function formattedResponseSize(): ?string
    {
        if ($this->response_bytes === null) {
            return null;
        }

        $bytes = $this->response_bytes;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return $this->formatSize($bytes / 1024).' KB';
        }

        return $this->formatSize($bytes / (1024 * 1024)).' MB';
    }

    private function formatSize(float $size): string
    {
        $formatted = number_format($size, 1, '.', '');

        if (str_ends_with($formatted, '.0')) {
            return substr($formatted, 0, -2);
        }

        return $formatted;
    }
}
