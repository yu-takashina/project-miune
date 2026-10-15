<?php

namespace App\Enums;

/**
 * 保存する抽出状態。本文を採用できたときは保存しない。
 */
enum ExtractionStatus: string
{
    case Empty = 'empty';
    case Failed = 'failed';
}
