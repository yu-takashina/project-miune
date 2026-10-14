<?php

namespace App\Jobs;

use App\Enums\SitemapStatus;
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

    public function __construct(public int $sitemapId, public bool $refresh = false) {}

    public static function depthErrorMessage(): string
    {
        return 'sitemap の深さが '.self::MAX_DEPTH.' を超えています。';
    }

    public static function lockKey(int $sitemapId): string
    {
        return 'sitemap-inspect:'.$sitemapId;
    }

    /**
     * 深さエラーのときと、同じ sitemap を別の処理が読み込み中のときに false を返す。呼び出し側は再巡回へ進まない。
     */
    public function handle(SitemapUrlsetReader $reader): bool
    {
        $lock = Cache::lock(self::lockKey($this->sitemapId), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            return $this->inspect($reader);
        } finally {
            $lock->release();
        }
    }

    private function inspect(SitemapUrlsetReader $reader): bool
    {
        $sitemap = Sitemap::query()->find($this->sitemapId);

        if ($sitemap === null || ! $this->shouldInspect($sitemap)) {
            return true;
        }

        $collected = (new SitemapTreeReader($reader))->collect($sitemap->url);

        if ($collected['status'] === 'too_deep') {
            $this->markDepthError($sitemap, $collected['message']);

            return false;
        }

        if ($collected['status'] === 'failed') {
            if (! $this->isRefresh()) {
                $this->markFailed($sitemap, $collected['message']);
            }

            return true;
        }

        if ($collected['status'] === 'invalid') {
            if (! $this->isRefresh()) {
                $sitemap->update([
                    'status' => SitemapStatus::Invalid,
                    'error_message' => $collected['message'],
                ]);
            }

            return true;
        }

        $locs = array_values(array_unique($collected['locs']));

        if ($locs === []) {
            if (! $this->isRefresh()) {
                $sitemap->update([
                    'status' => SitemapStatus::Invalid,
                    'error_message' => 'sitemap.xml の書式が正しくありません。',
                ]);
            }

            return true;
        }

        $pages = $this->syncPages($sitemap, $locs);

        if ($pages !== [] || $this->isRefresh() || $sitemap->status !== SitemapStatus::Crawling) {
            return true;
        }

        $sitemap->update([
            'status' => SitemapStatus::Completed,
        ]);

        return true;
    }

    private function shouldInspect(Sitemap $sitemap): bool
    {
        if ($this->isRefresh()) {
            return $sitemap->status === SitemapStatus::Completed;
        }

        return $sitemap->status === SitemapStatus::Pending;
    }

    private function isRefresh(): bool
    {
        return $this->refresh;
    }

    /**
     * @param  list<string>  $locs
     * @return list<SitemapPage>
     */
    private function syncPages(Sitemap $sitemap, array $locs): array
    {
        /** @var list<SitemapPage> $toCrawl */
        $toCrawl = [];

        $sitemap->getConnection()->transaction(function () use ($sitemap, $locs, &$toCrawl): void {
            $changes = [
                'crawled_at' => now(),
                'error_message' => null,
            ];

            if (! $this->isRefresh()) {
                $changes['status'] = SitemapStatus::Crawling;
            }

            $sitemap->update($changes);

            /** @var Collection<string, SitemapPage> $existing */
            $existing = $sitemap->pages()->get()->keyBy(
                fn (SitemapPage $page): string => $page->url_sha256,
            );

            /** @var array<string, true> $listed */
            $listed = [];

            foreach ($locs as $loc) {
                $hash = hash('sha256', $loc);
                $listed[$hash] = true;
                $page = $existing->get($hash);

                if (! $page instanceof SitemapPage) {
                    $toCrawl[] = SitemapPage::query()->create([
                        'sitemap_id' => $sitemap->id,
                        'url' => $loc,
                    ]);

                    continue;
                }

                if ($page->unlisted_at === null && $page->absence_count === 0) {
                    continue;
                }

                $skipFetch = $page->body_sha256 !== null;
                $page->unlisted_at = null;
                $page->absence_count = 0;

                if (! $skipFetch) {
                    $page->crawled_at = null;
                }

                $page->save();

                if (! $skipFetch) {
                    $toCrawl[] = $page;
                }
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

                $page->save();
            }

            if ($this->isRefresh() && $toCrawl !== []) {
                $sitemap->update([
                    'status' => SitemapStatus::Crawling,
                    'error_message' => null,
                ]);
            }
        });

        return $toCrawl;
    }

    private function markDepthError(Sitemap $sitemap, string $message): void
    {
        if ($this->isRefresh()) {
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

        if ($this->isRefresh() || $sitemap->status !== SitemapStatus::Pending) {
            return;
        }

        $this->markFailed($sitemap, 'sitemap.xml を取得できませんでした。');
    }
}
