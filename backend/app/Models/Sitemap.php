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
 * @property bool $searchable
 * @property SitemapStatus $status
 * @property string|null $error_message
 * @property int|null $absence_limit
 * @property Carbon|null $crawled_at sitemap.xml を読み込めた日時。失敗したときは更新しない。
 */
#[Fillable(['url', 'status', 'error_message', 'absence_limit', 'crawled_at', 'searchable'])]
class Sitemap extends Model
{
    /** @use HasFactory<SitemapFactory> */
    use HasFactory;

    /** 連続して sitemap から外れた URL を削除するまでの回数。null は削除しない。 */
    public const DEFAULT_ABSENCE_LIMIT = 1;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'searchable' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (Sitemap $sitemap): void {
            $sitemap->url_sha256 = hash('sha256', $sitemap->url);
        });

        // ページは外部キーの連鎖で消えるので、そのベクトル行だけは先に直接消す（MDEV-41227）。
        static::deleting(function (Sitemap $sitemap): void {
            SitemapPageEmbedding::query()
                ->whereIn('sitemap_page_id', $sitemap->pages()->select('id'))
                ->delete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'searchable' => 'boolean',
            'status' => SitemapStatus::class,
            'absence_limit' => 'integer',
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
