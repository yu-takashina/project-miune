<?php

use App\Enums\SitemapStatus;
use App\Jobs\InspectSitemap;
use App\Models\Sitemap;
use App\Services\CrawlDueSitemapPages;
use App\Services\SitemapUrlsetReader;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sitemaps:crawl', function (): void {
    app(CrawlDueSitemapPages::class)->run();
})->purpose('未取得の掲載URLを1秒に1ページ取得する');

Artisan::command('sitemaps:refresh', function (): void {
    $reader = new SitemapUrlsetReader;

    Sitemap::query()
        ->where('status', SitemapStatus::Completed)
        ->orderBy('id')
        ->each(function (Sitemap $sitemap) use ($reader): void {
            (new InspectSitemap($sitemap->id, true))->handle($reader);
        });
})->purpose('登録済みサイトマップの掲載URLを差分で更新する');

Artisan::command('sitemaps:recrawl', function (): void {
    app(CrawlDueSitemapPages::class)->requeueCompleted();
})->purpose('登録済みサイトマップを読み直し、掲載URLをすべて取得し直す');

Schedule::command('sitemaps:crawl')->everyMinute()->withoutOverlapping(10);
// recrawl も sitemap を読み直すので、同じ日に refresh を重ねて不在回数を二重に数えない
Schedule::command('sitemaps:refresh')->dailyAt('04:30')->days([1, 2, 3, 4, 5, 6])->withoutOverlapping(60);
Schedule::command('sitemaps:recrawl')->weeklyOn(0, '04:30')->withoutOverlapping(60);
