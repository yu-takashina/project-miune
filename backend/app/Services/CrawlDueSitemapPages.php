<?php

namespace App\Services;

use App\Jobs\CrawlSitemapPage;
use App\Models\SitemapPage;
use Illuminate\Database\Eloquent\Builder;

/**
 * 掲載中で、未取得か前回の取得から日が経ったページを、時間の許す範囲で取得する。
 */
class CrawlDueSitemapPages
{
    public const INTERVAL_SECONDS = 1;

    /** 1分おきの実行とかぶらないよう、1回はこの秒数で打ち切る。 */
    public const BUDGET_SECONDS = 55;

    /** 取得できたページを取り直すまでの日数。 */
    public const RECRAWL_DAYS = 7;

    public function __construct(
        public int $intervalSeconds = self::INTERVAL_SECONDS,
        public int $budgetSeconds = self::BUDGET_SECONDS,
    ) {}

    public function run(): void
    {
        $deadline = microtime(true) + $this->budgetSeconds;
        $started = false;
        $nextAt = microtime(true);
        $attempted = [];

        while (true) {
            $page = $this->nextPage($attempted);

            if (! $page instanceof SitemapPage) {
                break;
            }

            if ($started && ! $this->wait($nextAt, $deadline)) {
                break;
            }

            $fetchedAt = microtime(true);
            (new CrawlSitemapPage($page->id))->handle();
            $attempted[] = $page->id;
            $nextAt = $fetchedAt + $this->intervalSeconds;
            $started = true;
        }
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
    private function nextPage(array $exceptIds = []): ?SitemapPage
    {
        $now = now();

        $page = SitemapPage::query()
            ->whereNull('unlisted_at')
            ->when($exceptIds !== [], function (Builder $query) use ($exceptIds): void {
                $query->whereNotIn('id', $exceptIds);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('crawled_at')
                    ->orWhere('crawled_at', '<=', $now->copy()->subDays(self::RECRAWL_DAYS));
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('fetch_retry_at')
                    ->orWhere('fetch_retry_at', '<=', $now);
            })
            ->orderBy('id')
            ->first();

        return $page instanceof SitemapPage ? $page : null;
    }
}
