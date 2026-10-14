<?php

namespace App\Models;

use App\Enums\SitemapStatus;
use Database\Factories\SitemapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $url
 * @property string $url_sha256
 * @property SitemapStatus $status
 * @property string|null $error_message
 * @property int|null $absence_limit
 * @property int|null $recrawl_after_page_id 再巡回で取り直し終えた最後のページ ID。null は再巡回中でない。
 * @property Carbon|null $crawled_at sitemap.xml を読み込めた日時。失敗したときは更新しない。
 */
#[Fillable(['url', 'status', 'error_message', 'absence_limit', 'recrawl_after_page_id', 'crawled_at'])]
class Sitemap extends Model
{
    /** @use HasFactory<SitemapFactory> */
    use HasFactory;

    /** 連続して sitemap から外れた URL を削除するまでの回数。null は削除しない。 */
    public const DEFAULT_ABSENCE_LIMIT = 1;

    protected static function booted(): void
    {
        static::saving(function (Sitemap $sitemap): void {
            $sitemap->url_sha256 = hash('sha256', $sitemap->url);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SitemapStatus::class,
            'absence_limit' => 'integer',
            'recrawl_after_page_id' => 'integer',
            'crawled_at' => 'datetime',
        ];
    }

    public function absenceLimitLabel(): string
    {
        if ($this->absence_limit === null) {
            return '削除しない';
        }

        return $this->absence_limit.'回';
    }

    /**
     * @return HasMany<SitemapPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(SitemapPage::class);
    }
}
