<?php

namespace App\Services;

use App\Enums\ExtractionStatus;
use App\Models\SitemapPage;
use Illuminate\Database\Eloquent\Builder;

class ExtractStoredSitemapPages
{
    public function run(): int
    {
        $count = 0;
        $extractor = new ExtractSitemapPage;

        SitemapPage::query()
            ->whereNotNull('body')
            ->where(function (Builder $query): void {
                $query->whereNull('extracted_at')
                    ->orWhere('extraction_status', ExtractionStatus::Failed);
            })
            ->lazyById(10)
            ->each(function (SitemapPage $page) use ($extractor, &$count): void {
                $extractor->apply($page, null);
                $count++;
            });

        return $count;
    }
}
