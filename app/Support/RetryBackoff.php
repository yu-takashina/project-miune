<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 失敗が続くほど次の再試行を遅らせる。1分、2分、4分…と倍にし、24時間で止める。
 */
class RetryBackoff
{
    public const MAX_MINUTES = 1440;

    /**
     * @param  int  $failures  今回の失敗を含めた連続失敗回数
     */
    public static function minutes(int $failures): int
    {
        if ($failures <= 1) {
            return 1;
        }

        if ($failures > 11) {
            return self::MAX_MINUTES;
        }

        return min(2 ** ($failures - 1), self::MAX_MINUTES);
    }

    public static function nextAt(int $failures): Carbon
    {
        return now()->addMinutes(self::minutes($failures));
    }
}
