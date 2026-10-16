<?php

namespace App\Enums;

enum CrawlErrorKind: string
{
    case Sitemap = 'sitemap';
    case Page = 'page';
    case Embedding = 'embedding';
}
