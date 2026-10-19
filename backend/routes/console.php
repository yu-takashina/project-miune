<?php

use App\Enums\SitemapStatus;
use App\Jobs\InspectSitemap;
use App\Models\Sitemap;
use App\Services\CrawlDueSitemapPages;
use App\Services\EmbedPendingSitemapPages;
use App\Services\ExtractStoredSitemapPages;
use App\Services\SitemapUrlsetReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sitemaps:inspect', function (): void {
    $reader = new SitemapUrlsetReader;

    Sitemap::query()
        ->whereIn('status', [SitemapStatus::Pending, SitemapStatus::Completed])
        ->where(function (Builder $query): void {
            $query->whereNull('crawled_at')
                ->orWhere('crawled_at', '<=', now()->subDay());
        })
        ->lazyById()
        ->each(function (Sitemap $sitemap) use ($reader): void {
            $job = new InspectSitemap($sitemap->id);

            try {
                $job->handle($reader);
            } catch (Throwable $exception) {
                report($exception);
                $job->failed($exception);
            }
        });
})->purpose('未読か前回の読み込みから1日経ったサイトマップを読み、掲載URLを同期する');

Artisan::command('sitemaps:crawl', function (): void {
    app(CrawlDueSitemapPages::class)->run();
})->purpose('未取得か前回の取得から7日経った掲載URLを1秒に1ページ取得し、本文を抜く');

Artisan::command('sitemaps:embed', function (): void {
    $count = (new EmbedPendingSitemapPages)->run();
    $this->info($count.' ページのベクトル化を試しました。');
})->purpose('採用本文が変わったページをベクトル化する');

Artisan::command('sitemaps:extract', function (): void {
    $count = (new ExtractStoredSitemapPages)->run();
    $this->info($count.' ページを抽出しました。');
})->purpose('保存済みの本文から Readability と Trafilatura で採用する本文を抜く');

Schedule::command('sitemaps:inspect')->hourly()->withoutOverlapping(60);
Schedule::command('sitemaps:crawl')->everyMinute()->withoutOverlapping(10);
Schedule::command('sitemaps:embed')->everyMinute()->withoutOverlapping(10);
