<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;

class SearchSitemapPages
{
    public const LIMIT = 20;

    /**
     * ベクトル索引から先に取る件数。検索対象外や古いベクトルを除いた残りを出すので、LIMIT に届かないことがある。
     */
    public const CANDIDATES = 100;

    /**
     * 関連度は 1 から余弦距離を引いた値。1 が同じ向き、0 が直交。
     *
     * @param  list<float>  $vector
     * @return list<array{id: int, relevance: float}>
     */
    public function nearest(array $vector): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $this->nearestFromSqlite($vector);
        }

        try {
            $encoded = json_encode($vector, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OpenAiEmbeddingException('埋め込みの次元が不正です。', false, false, $exception);
        }

        return $this->nearestFromMariaDb($encoded);
    }

    /**
     * @param  list<float>  $vector
     * @return list<array{id: int, relevance: float}>
     */
    private function nearestFromSqlite(array $vector): array
    {
        $rows = DB::table('sitemap_page_embeddings')
            ->join('sitemap_pages', 'sitemap_pages.id', '=', 'sitemap_page_embeddings.sitemap_page_id')
            ->where('sitemap_pages.searchable', true)
            ->whereNull('sitemap_pages.unlisted_at')
            ->whereNotNull('sitemap_pages.body')
            ->where(function (Builder $query): void {
                $query->whereNull('sitemap_pages.http_status')
                    ->orWhere('sitemap_pages.http_status', '<>', 404);
            })
            ->whereColumn(
                'sitemap_page_embeddings.article_text_sha256',
                'sitemap_pages.article_text_sha256',
            )
            ->get([
                'sitemap_page_embeddings.sitemap_page_id as page_id',
                'sitemap_page_embeddings.embedding',
            ]);

        /** @var array<int, float> $best */
        $best = [];

        foreach ($rows as $row) {
            if (! is_numeric($row->page_id)) {
                continue;
            }

            $stored = $this->decode($row->embedding);

            if ($stored === null) {
                continue;
            }

            $id = (int) $row->page_id;
            $distance = $this->cosineDistance($vector, $stored);

            if (! isset($best[$id]) || $distance < $best[$id]) {
                $best[$id] = $distance;
            }
        }

        /** @var list<array{id: int, distance: float}> $ranked */
        $ranked = [];

        foreach ($best as $id => $distance) {
            $ranked[] = ['id' => $id, 'distance' => $distance];
        }

        usort($ranked, function (array $left, array $right): int {
            $byDistance = $left['distance'] <=> $right['distance'];

            if ($byDistance !== 0) {
                return $byDistance;
            }

            return $left['id'] <=> $right['id'];
        });

        $matches = [];

        foreach ($ranked as $row) {
            $matches[] = [
                'id' => $row['id'],
                'relevance' => 1.0 - $row['distance'],
            ];

            if (count($matches) === self::LIMIT) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @return list<array{id: int, relevance: float}>
     */
    private function nearestFromMariaDb(string $encoded): array
    {
        $candidates = self::CANDIDATES;
        $rows = DB::select(
            <<<SQL
                SELECT candidates.sitemap_page_id AS page_id, candidates.distance AS distance
                FROM (
                    SELECT
                        sitemap_page_id,
                        article_text_sha256,
                        VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) AS distance
                    FROM sitemap_page_embeddings
                    ORDER BY VEC_DISTANCE_COSINE(embedding, VEC_FromText(?))
                    LIMIT {$candidates}
                ) AS candidates
                INNER JOIN sitemap_pages ON sitemap_pages.id = candidates.sitemap_page_id
                WHERE sitemap_pages.searchable = 1
                  AND sitemap_pages.unlisted_at IS NULL
                  AND sitemap_pages.body IS NOT NULL
                  AND (sitemap_pages.http_status IS NULL OR sitemap_pages.http_status <> 404)
                  AND candidates.article_text_sha256 = sitemap_pages.article_text_sha256
                ORDER BY candidates.distance, candidates.sitemap_page_id
            SQL,
            [$encoded, $encoded],
        );

        $matches = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! $row instanceof stdClass || ! is_numeric($row->page_id ?? null) || ! is_numeric($row->distance ?? null)) {
                continue;
            }

            $id = (int) $row->page_id;

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $matches[] = [
                'id' => $id,
                'relevance' => 1.0 - (float) $row->distance,
            ];

            if (count($matches) === self::LIMIT) {
                break;
            }
        }

        return $matches;
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
