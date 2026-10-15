<?php

namespace App\Services;

use App\Enums\SitemapStatus;
use App\Jobs\CrawlSitemapPage;
use App\Jobs\InspectSitemap;
use App\Models\Sitemap;
use App\Models\SitemapPage;
use Illuminate\Database\Eloquent\Builder;

/**
 * 確認待ちの sitemap を読み、未取得の掲載 URL を時間の許す範囲で取得する。
 */
class CrawlDueSitemapPages
{
    public const INTERVAL_SECONDS = 1;

    /** 1分おきの実行とかぶらないよう、1回はこの秒数で打ち切る。 */
    public const BUDGET_SECONDS = 55;

    /** 画面からの巡回は、PHP の実行時間に収まる範囲で取り切る。 */
    public const MANUAL_BUDGET_SECONDS = 170;

    public function __construct(
        public int $intervalSeconds = self::INTERVAL_SECONDS,
        public int $budgetSeconds = self::BUDGET_SECONDS,
    ) {}

    public function run(): void
    {
        $this->inspectPending();
        $this->crawlListedPages();
    }

    public function inspectPending(): void
    {
        $reader = new SitemapUrlsetReader;

        Sitemap::query()
            ->where('status', SitemapStatus::Pending)
            ->orderBy('id')
            ->each(function (Sitemap $sitemap) use ($reader): void {
                (new InspectSitemap($sitemap->id))->handle($reader);
            });
    }

    /**
     * 指定した sitemap の掲載 URL を、取得済みのものも含めて今すぐ巡回する。
     */
    public function crawlSitemap(Sitemap $sitemap): void
    {
        if ($sitemap->status === SitemapStatus::Crawling) {
            return;
        }

        if (! $this->requeueSitemap($sitemap)) {
            return;
        }

        $this->crawlListedPages($sitemap->id);
    }

    /**
     * 巡回済の sitemap を読み直し、掲載 URL を取得済みのものも含めて再取得の対象にする。
     * 取得は毎分の crawlListedPages が行い、取得できたページだけ crawled_at を上書きする。
     */
    public function requeueCompleted(): void
    {
        Sitemap::query()
            ->where('status', SitemapStatus::Completed)
            ->orderBy('id')
            ->each(function (Sitemap $sitemap): void {
                $this->requeueSitemap($sitemap);
            });
    }

    private function requeueSitemap(Sitemap $sitemap): bool
    {
        if (! $this->prepareSitemap($sitemap)) {
            return false;
        }

        $sitemap->refresh();

        if (! in_array($sitemap->status, [SitemapStatus::Crawling, SitemapStatus::Completed], true)) {
            return false;
        }

        $sitemap->update([
            'status' => SitemapStatus::Crawling,
            'error_message' => null,
            'recrawl_after_page_id' => 0,
        ]);

        return true;
    }

    public function crawlListedPages(?int $sitemapId = null): void
    {
        $deadline = microtime(true) + $this->budgetSeconds;
        $started = false;
        $nextAt = microtime(true);
        $attempted = [];

        while (true) {
            $page = $this->nextPage($sitemapId, $attempted);

            if (! $page instanceof SitemapPage) {
                break;
            }

            if ($started && ! $this->wait($nextAt, $deadline)) {
                break;
            }

            $fetchedAt = microtime(true);
            (new CrawlSitemapPage($page->id))->handle();
            $attempted[] = $page->id;
            $this->advanceRecrawl($page);
            $nextAt = $fetchedAt + $this->intervalSeconds;
            $started = true;
        }

        $this->completeFinishedSitemaps($sitemapId);
    }

    private function prepareSitemap(Sitemap $sitemap): bool
    {
        $sitemap->refresh();
        $reader = new SitemapUrlsetReader;

        if ($sitemap->status === SitemapStatus::Completed) {
            return (new InspectSitemap($sitemap->id, true))->handle($reader);
        }

        if ($sitemap->status === SitemapStatus::Crawling) {
            return true;
        }

        if ($sitemap->status !== SitemapStatus::Pending) {
            $sitemap->update([
                'status' => SitemapStatus::Pending,
                'error_message' => null,
            ]);
        }

        return (new InspectSitemap($sitemap->id))->handle($reader);
    }

    private function wait(float $nextAt, float $deadline): bool
    {
        if ($this->intervalSeconds === 0) {
            return microtime(true) < $deadline;
        }

        if ($nextAt >= $deadline) {
            return false;
        }

        $sleepFor = $nextAt - microtime(true);

        if ($sleepFor > 0) {
            usleep((int) round($sleepFor * 1_000_000));
        }

        return true;
    }

    /**
     * @param  list<int>  $exceptIds
     */
    private function nextPage(?int $sitemapId = null, array $exceptIds = []): ?SitemapPage
    {
        $page = SitemapPage::query()
            ->whereNull('unlisted_at')
            ->when($sitemapId !== null, function (Builder $query) use ($sitemapId): void {
                $query->where('sitemap_id', $sitemapId);
            })
            ->when($exceptIds !== [], function (Builder $query) use ($exceptIds): void {
                $query->whereNotIn('id', $exceptIds);
            })
            ->whereHas('sitemap', function (Builder $query): void {
                $query->where('status', SitemapStatus::Crawling);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('crawled_at')
                    ->orWhere(fn (Builder $query) => $query->missingEmbedding())
                    ->orWhereHas('sitemap', function (Builder $sitemap): void {
                        $sitemap->whereColumn('sitemaps.recrawl_after_page_id', '<', 'sitemap_pages.id');
                    });
            })
            ->orderBy('id')
            ->first();

        return $page instanceof SitemapPage ? $page : null;
    }

    private function advanceRecrawl(SitemapPage $page): void
    {
        Sitemap::query()
            ->whereKey($page->sitemap_id)
            ->where('recrawl_after_page_id', '<', $page->id)
            ->update(['recrawl_after_page_id' => $page->id]);
    }

    private function completeFinishedSitemaps(?int $sitemapId = null): void
    {
        Sitemap::query()
            ->where('status', SitemapStatus::Crawling)
            ->when($sitemapId !== null, function (Builder $query) use ($sitemapId): void {
                $query->whereKey($sitemapId);
            })
            ->orderBy('id')
            ->each(function (Sitemap $sitemap): void {
                $recrawlAfter = $sitemap->recrawl_after_page_id;
                $remaining = $sitemap->pages()
                    ->whereNull('unlisted_at')
                    ->where(function (Builder $query) use ($recrawlAfter): void {
                        $query->whereNull('crawled_at')
                            ->orWhere(fn (Builder $query) => $query->missingEmbedding());

                        if ($recrawlAfter !== null) {
                            $query->orWhere('id', '>', $recrawlAfter);
                        }
                    })
                    ->exists();

                if ($remaining) {
                    return;
                }

                $sitemap->update([
                    'status' => SitemapStatus::Completed,
                    'recrawl_after_page_id' => null,
                ]);
            });
    }
}
