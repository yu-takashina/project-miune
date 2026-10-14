<?php

namespace App\Services;

use SimpleXMLElement;

class SitemapUrlsetReader
{
    private const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const INVALID_MESSAGE = 'sitemap.xml の書式が正しくありません。';

    public function read(string $xml): SitemapUrlsetResult
    {
        $document = $this->document($xml);

        if (! $document instanceof SimpleXMLElement) {
            return SitemapUrlsetResult::invalid(self::INVALID_MESSAGE);
        }

        $name = $document->getName();
        $index = $name === 'sitemapindex';

        if ($name !== 'urlset' && ! $index) {
            return SitemapUrlsetResult::invalid(self::INVALID_MESSAGE);
        }

        $namespaces = $document->getDocNamespaces();

        if (! is_array($namespaces) || ! in_array(self::NAMESPACE, $namespaces, true)) {
            return SitemapUrlsetResult::invalid(self::INVALID_MESSAGE);
        }

        $nodes = $index
            ? $document->children(self::NAMESPACE)->sitemap
            : $document->children(self::NAMESPACE)->url;
        $locs = $this->locs($nodes);

        if ($locs === null || $locs === []) {
            return SitemapUrlsetResult::invalid(self::INVALID_MESSAGE);
        }

        return $index
            ? SitemapUrlsetResult::index($locs)
            : SitemapUrlsetResult::valid($locs);
    }

    /**
     * @return list<string>|null
     */
    private function locs(SimpleXMLElement $nodes): ?array
    {
        $locs = [];

        foreach ($nodes as $node) {
            $loc = trim((string) $node->children(self::NAMESPACE)->loc);

            if (! $this->isHttpUrl($loc)) {
                return null;
            }

            $locs[] = $loc;
        }

        return array_values(array_unique($locs));
    }

    private function document(string $xml): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, options: LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            return null;
        }

        return $document;
    }

    private function isHttpUrl(string $loc): bool
    {
        if ($loc === '') {
            return false;
        }

        $parts = parse_url($loc);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        return ($scheme === 'http' || $scheme === 'https')
            && is_string($host)
            && $host !== '';
    }
}
