<?php

namespace App\Jobs;

use App\Enums\SitemapStatus;
use App\Models\CrawlErrorLog;
use App\Models\Sitemap;
use App\Models\SitemapPage;
use App\Services\SitemapTreeReader;
use App\Services\SitemapUrlsetReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class InspectSitemap implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** 登録された sitemap を深さ 1 として辿る上限。 */
    public const MAX_DEPTH = 5;

    /** sitemap を辿り終えるまでの時間より長くする。子 sitemap 1件で最大 30 秒かかる。 */
    public const LOCK_SECONDS = 600;

    /**
     * @param  bool  $retryFailed  取得失敗と書式エラーも、ロックを取ってから読み直す。一覧の「今すぐ巡回」だけが true にする。
     */
    public function __construct(public int $sitemapId, public bool $retryFailed = false) {}

    public static function depthErrorMessage(): string
    {
        return 'sitemap の深さが '.self::MAX_DEPTH.' を超えています。';
    }

    public static function lockKey(int $sitemapId): string
    {
        return 'sitemap-inspect:'.$sitemapId;
    }

    /**
     * 確認待ちと巡回済の sitemap を読み、掲載 URL を同期する。ページの取得は sitemaps:crawl が行う。
     * 同じ sitemap を別の処理が読み込み中のときは何もしない。
     */
    public function handle(SitemapUrlsetReader $reader): bool
    {
        $lock = Cache::lock(self::lockKey($this->sitemapId), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->inspect($reader);
        } finally {
            $lock->release();
        }

        return true;
    }

    private function inspect(SitemapUrlsetReader $reader): void
    {
        $sitemap = Sitemap::query()->find($this->sitemapId);

        if ($sitemap === null) {
            return;
        }

        if ($this->retryFailed && in_array($sitemap->status, [SitemapStatus::Failed, SitemapStatus::Invalid], true)) {
            $sitemap->update([
                'status' => SitemapStatus::Pending,
                'error_message' => null,
            ]);
        }

        if (! in_array($sitemap->status, [SitemapStatus::Pending, SitemapStatus::Completed], true)) {
            return;
        }

        $collected = (new SitemapTreeReader($reader))->collect($sitemap->url);

        if ($collected['status'] === 'too_deep') {
            $this->markDepthError($sitemap, $collected['message']);

            return;
        }

        if ($collected['status'] === 'failed') {
            CrawlErrorLog::forSitemap($sitemap, $collected['message']);

            if (! $this->isRefresh($sitemap)) {
                $this->markFailed($sitemap, $collected['message']);
            }

            return;
        }

        if ($collected['status'] === 'invalid') {
            $this->markInvalid($sitemap, $collected['message']);

            return;
        }

        $locs = array_values(array_unique($collected['locs']));

        if ($locs === []) {
            $this->markInvalid($sitemap, 'sitemap.xml の書式が正しくありません。');

            return;
        }

        $this->syncPages($sitemap, $locs);
    }

    /**
     * 一度読めた sitemap の読み直し。失敗しても状態は巡回済のまま残す。
     */
    private function isRefresh(Sitemap $sitemap): bool
    {
        return $sitemap->status === SitemapStatus::Completed;
    }

    /**
     * @param  list<string>  $locs
     */
    private function syncPages(Sitemap $sitemap, array $locs): void
    {
        $sitemap->getConnection()->transaction(function () use ($sitemap, $locs): void {
            $sitemap->update([
                'status' => SitemapStatus::Completed,
                'crawled_at' => now(),
                'error_message' => null,
            ]);

            // 本文まで読むとメモリを使い切る。掲載の突き合わせに必要な列だけ取る。
            /** @var Collection<string, SitemapPage> $existing */
            $existing = $sitemap->pages()->get([
                'id',
                'url_sha256',
                'unlisted_at',
                'absence_count',
                'body_sha256',
                'crawled_at',
            ])->keyBy(
                fn (SitemapPage $page): string => $page->url_sha256,
            );

            /** @var array<string, true> $listed */
            $listed = [];

            foreach ($locs as $loc) {
                $hash = hash('sha256', $loc);
                $listed[$hash] = true;
                $page = $existing->get($hash);

                if (! $page instanceof SitemapPage) {
                    SitemapPage::query()->create([
                        'sitemap_id' => $sitemap->id,
                        'url' => $loc,
                    ]);

                    continue;
                }

                if ($page->unlisted_at === null && $page->absence_count === 0) {
                    continue;
                }

                $page->unlisted_at = null;
                $page->absence_count = 0;

                if ($page->body_sha256 === null) {
                    $page->crawled_at = null;
                }

                // 本文列を読んでいないので、保存時の本文ハッシュ更新は動かさない。
                $page->saveQuietly();
            }

            foreach ($existing as $hash => $page) {
                if (isset($listed[$hash])) {
                    continue;
                }

                $page->absence_count++;

                if ($page->unlisted_at === null) {
                    $page->unlisted_at = now();
                }

                if ($sitemap->absence_limit !== null && $page->absence_count >= $sitemap->absence_limit) {
                    $page->delete();

                    continue;
                }

                $page->saveQuietly();
            }
        });
    }

    private function markDepthError(Sitemap $sitemap, string $message): void
    {
        CrawlErrorLog::forSitemap($sitemap, $message);

        if ($this->isRefresh($sitemap)) {
            $sitemap->update([
                'error_message' => $message,
            ]);

            return;
        }

        $sitemap->update([
            'status' => SitemapStatus::Invalid,
            'error_message' => $message,
        ]);
    }

    private function markInvalid(Sitemap $sitemap, string $message): void
    {
        CrawlErrorLog::forSitemap($sitemap, $message);

        if ($this->isRefresh($sitemap)) {
            return;
        }

        $sitemap->update([
            'status' => SitemapStatus::Invalid,
            'error_message' => $message,
        ]);
    }

    private function markFailed(Sitemap $sitemap, string $message): void
    {
        $sitemap->update([
            'status' => SitemapStatus::Failed,
            'error_message' => $message,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $sitemap = Sitemap::query()->find($this->sitemapId);

        if ($sitemap === null) {
            return;
        }

        $message = 'sitemap.xml を取得できませんでした。';
        CrawlErrorLog::forSitemap($sitemap, $message);

        if ($sitemap->status !== SitemapStatus::Pending) {
            return;
        }

        $this->markFailed($sitemap, $message);
    }
}
