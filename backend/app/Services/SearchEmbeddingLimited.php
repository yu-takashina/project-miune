<?php

namespace App\Services;

use RuntimeException;

class SearchEmbeddingLimited extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('検索語のベクトル化が上限に達しました。');
    }
}
