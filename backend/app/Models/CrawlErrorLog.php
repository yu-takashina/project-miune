<?php

namespace App\Models;

use App\Enums\CrawlErrorKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * サイトマップの読み込み、ページの取得、ベクトル化で起きたエラーの履歴。
 *
 * @property CrawlErrorKind $kind
 * @property int|null $sitemap_id
 * @property int|null $sitemap_page_id
 * @property string|null $url 記録したときの URL。サイトマップやページを削除しても残す。
 * @property int|null $http_status
 * @property string $message
 * @property Carbon|null $created_at
 */
#[Fillable([
    'kind',
    'sitemap_id',
    'sitemap_page_id',
    'url',
    'http_status',
    'message',
    'created_at',
])]
class CrawlErrorLog extends Model
{
    public $timestamps = false;

    public static function forSitemap(Sitemap $sitemap, string $message): void
    {
        self::query()->create([
            'kind' => CrawlErrorKind::Sitemap,
            'sitemap_id' => $sitemap->id,
            'url' => $sitemap->url,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    public static function forPage(SitemapPage $page, string $message, ?int $httpStatus = null, CrawlErrorKind $kind = CrawlErrorKind::Page): void
    {
        self::query()->create([
            'kind' => $kind,
            'sitemap_id' => $page->sitemap_id,
            'sitemap_page_id' => $page->id,
            'url' => $page->url,
            'http_status' => $httpStatus,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    /**
     * ページに結び付かないエラー。API キーの誤りなど。
     */
    public static function general(CrawlErrorKind $kind, string $message): void
    {
        self::query()->create([
            'kind' => $kind,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CrawlErrorKind::class,
            'http_status' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
