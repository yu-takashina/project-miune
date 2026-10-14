<?php

namespace App\Enums;

enum SitemapStatus: string
{
    case Pending = 'pending';
    case Invalid = 'invalid';
    case Crawling = 'crawling';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '確認待ち',
            self::Invalid => '書式エラー',
            self::Crawling => '巡回中',
            self::Completed => '巡回済',
            self::Failed => '取得失敗',
        };
    }
}
