<?php

namespace App\Services;

use App\Models\SitemapPage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;

class SearchSitemapPages
{
    public const LIMIT = 20;

    /**
     * 関連度は 1 から余弦距離を引いた値。1 が同じ向き、0 が直交。
     *
     * @param  list<float>  $vector
     * @return list<array{id: int, relevance: float}>
     */
    public function nearest(array $vector, int $limit = self::LIMIT): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $this->nearestFromSqlite($vector, $limit);
        }

        try {
            return $this->nearestFromMariaDb($vector, $limit);
        } catch (JsonException $exception) {
            throw new OpenAiEmbeddingException('埋め込みの次元が不正です。', false, false, $exception);
        }
    }

    /**
     * 検索対象のページ（SitemapPage::scopeForSearch）の、今の採用本文のベクトル行。
     */
    private function searchableEmbeddings(): Builder
    {
        return SitemapPage::query()
            ->forSearch()
            ->join('sitemap_page_embeddings', 'sitemap_page_embeddings.sitemap_page_id', '=', 'sitemap_pages.id')
            ->whereColumn(
                'sitemap_page_embeddings.article_text_sha256',
                'sitemap_pages.article_text_sha256',
            )
            ->toBase();
    }

    /**
     * @param  list<float>  $vector
     * @return list<array{id: int, relevance: float}>
     */
    private function nearestFromSqlite(array $vector, int $limit): array
    {
        $rows = $this->searchableEmbeddings()
            ->get([
                'sitemap_page_embeddings.sitemap_page_id as page_id',
                'sitemap_page_embeddings.embedding',
                'sitemap_pages.url as url',
                'sitemap_pages.article_text_sha256 as text_sha256',
            ]);

        /** @var array<int, array{distance: float, url: string, text: string}> $best */
        $best = [];

        foreach ($rows as $row) {
            $url = $row->url;
            $text = $row->text_sha256;

            if (! is_numeric($row->page_id) || ! is_string($url) || $url === '' || ! is_string($text)) {
                continue;
            }

            $stored = $this->decode($row->embedding);

            if ($stored === null) {
                continue;
            }

            $id = (int) $row->page_id;
            $distance = $this->cosineDistance($vector, $stored);

            if (! isset($best[$id]) || $distance < $best[$id]['distance']) {
                $best[$id] = [
                    'distance' => $distance,
                    'url' => $url,
                    'text' => $text,
                ];
            }
        }

        /** @var list<array{id: int, distance: float, url: string, text: string}> $ranked */
        $ranked = [];

        foreach ($best as $id => $row) {
            $ranked[] = ['id' => $id, 'distance' => $row['distance'], 'url' => $row['url'], 'text' => $row['text']];
        }

        usort($ranked, function (array $left, array $right): int {
            $byDistance = $left['distance'] <=> $right['distance'];

            if ($byDistance !== 0) {
                return $byDistance;
            }

            return $left['id'] <=> $right['id'];
        });

        return $this->firstPerUrlAndText($ranked, $limit);
    }

    /**
     * ベクトル索引は使わず、検索対象のページすべてと距離を比べる。
     * MariaDB の索引は近似なので本当に近いページを取りこぼし、先に候補を絞ると検索対象外の分だけ件数も欠けるため。
     * selectVectorDistance は接続が mariadb のときだけ使える。mysql の接続では、相手が MariaDB でも例外になる。
     *
     * @param  list<float>  $vector
     * @return list<array{id: int, relevance: float}>
     *
     * @throws JsonException
     */
    private function nearestFromMariaDb(array $vector, int $limit): array
    {
        $rows = $this->searchableEmbeddings()
            ->select([
                'sitemap_pages.id as page_id',
                'sitemap_pages.url as url',
                'sitemap_pages.article_text_sha256 as text_sha256',
            ])
            ->selectVectorDistance('sitemap_page_embeddings.embedding', $vector, 'distance')
            ->orderBy('distance')
            ->orderBy('sitemap_pages.id')
            ->get();

        /** @var list<array{id: int, distance: float, url: string, text: string}> $ranked */
        $ranked = [];

        foreach ($rows as $row) {
            $url = $row->url;
            $text = $row->text_sha256;

            if (! is_numeric($row->page_id) || ! is_string($url) || $url === '' || ! is_string($text) || ! is_numeric($row->distance)) {
                continue;
            }

            $ranked[] = ['id' => (int) $row->page_id, 'distance' => (float) $row->distance, 'url' => $url, 'text' => $text];
        }

        return $this->firstPerUrlAndText($ranked, $limit);
    }

    /**
     * 行は近い順、同じ距離ならページ ID の小さい順に渡す。
     * 同じ URL と同じ採用本文は最初の行だけを残す。サイトは同じ記事を別の URL でも載せるので、URL だけでは除けない。
     * 同じページの行は URL も同じなので、最も近い行が残る。
     *
     * @param  list<array{id: int, distance: float, url: string, text: string}>  $ranked
     * @return list<array{id: int, relevance: float}>
     */
    private function firstPerUrlAndText(array $ranked, int $limit): array
    {
        $matches = [];
        /** @var array<string, true> $seenUrls */
        $seenUrls = [];
        /** @var array<string, true> $seenTexts */
        $seenTexts = [];

        foreach ($ranked as $row) {
            if (isset($seenUrls[$row['url']]) || isset($seenTexts[$row['text']])) {
                continue;
            }

            $seenUrls[$row['url']] = true;
            $seenTexts[$row['text']] = true;
            $matches[] = [
                'id' => $row['id'],
                'relevance' => 1.0 - $row['distance'],
            ];

            if (count($matches) === $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * 検索対象のページ（SitemapPage::scopeForSearch）のうち、本文に検索語がそのまま含まれるもの。ベクトルの近傍ではない。
     * 同じ URL と同じ採用本文は、ページ ID の小さい方だけを返す。
     *
     * @return list<int>
     */
    public function containing(string $term, int $limit = self::LIMIT): array
    {
        $pattern = '%'.$this->escapeLike($term).'%';
        $rows = SitemapPage::query()
            ->forSearch()
            ->whereNotNull('sitemap_pages.article_text')
            ->whereRaw('sitemap_pages.article_text LIKE ? ESCAPE ?', [$pattern, '\\'])
            ->orderBy('sitemap_pages.id')
            ->toBase()
            ->select([
                'sitemap_pages.id as page_id',
                'sitemap_pages.url as url',
                'sitemap_pages.article_text_sha256 as text_sha256',
            ])
            ->cursor();

        $ids = [];
        /** @var array<string, true> $seenUrls */
        $seenUrls = [];
        /** @var array<string, true> $seenTexts */
        $seenTexts = [];

        foreach ($rows as $row) {
            $url = $row->url;
            $text = $row->text_sha256;

            if (! is_numeric($row->page_id) || ! is_string($url) || ! is_string($text)) {
                continue;
            }

            if (isset($seenUrls[$url]) || isset($seenTexts[$text])) {
                continue;
            }

            $seenUrls[$url] = true;
            $seenTexts[$text] = true;
            $ids[] = (int) $row->page_id;

            if (count($ids) === $limit) {
                break;
            }
        }

        return $ids;
    }

    private function escapeLike(string $term): string
    {
        return addcslashes($term, '\\%_');
    }

    /**
     * @return list<float>|null
     */
    private function decode(mixed $stored): ?array
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $vector = [];

        foreach ($decoded as $value) {
            if (is_int($value)) {
                $vector[] = (float) $value;

                continue;
            }

            if (! is_float($value)) {
                return null;
            }

            $vector[] = $value;
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function cosineDistance(array $left, array $right): float
    {
        if ($left === [] || count($left) !== count($right)) {
            return 2.0;
        }

        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        foreach ($left as $index => $value) {
            $other = $right[$index];
            $dot += $value * $other;
            $leftNorm += $value * $value;
            $rightNorm += $other * $other;
        }

        if ($leftNorm === 0.0 || $rightNorm === 0.0) {
            return 2.0;
        }

        return 1.0 - ($dot / (sqrt($leftNorm) * sqrt($rightNorm)));
    }
}
