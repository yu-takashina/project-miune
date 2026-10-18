<?php

namespace App\Services;

final readonly class SitemapUrlsetResult
{
    /**
     * @param  list<string>  $locs  urlset のときは掲載 URL、sitemapindex のときは子 sitemap の URL
     */
    public function __construct(
        public bool $valid,
        public ?string $errorMessage,
        public array $locs,
        public bool $index,
    ) {}

    /**
     * @param  list<string>  $locs
     */
    public static function valid(array $locs): self
    {
        return new self(true, null, $locs, false);
    }

    /**
     * @param  list<string>  $locs
     */
    public static function index(array $locs): self
    {
        return new self(true, null, $locs, true);
    }

    public static function invalid(string $message): self
    {
        return new self(false, $message, [], false);
    }
}
