<?php

namespace App\Services;

use App\Jobs\InspectSitemap;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SitemapTreeReader
{
    public function __construct(private SitemapUrlsetReader $reader) {}

    /**
     * @return array{status: 'ok', locs: list<string>}|array{status: 'invalid', message: string}|array{status: 'failed', message: string}|array{status: 'too_deep', message: string}
     */
    public function collect(string $url): array
    {
        /** @var array<string, true> $seen */
        $seen = [];

        return $this->read($url, 1, $seen);
    }

    /**
     * @param  array<string, true>  $seen
     * @return array{status: 'ok', locs: list<string>}|array{status: 'invalid', message: string}|array{status: 'failed', message: string}|array{status: 'too_deep', message: string}
     */
    private function read(string $url, int $depth, array &$seen): array
    {
        if (isset($seen[$url])) {
            return ['status' => 'ok', 'locs' => []];
        }

        if ($depth > InspectSitemap::MAX_DEPTH) {
            return ['status' => 'too_deep', 'message' => InspectSitemap::depthErrorMessage()];
        }

        $seen[$url] = true;

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => 'miune'])
                ->get($url);
        } catch (ConnectionException) {
            return ['status' => 'failed', 'message' => 'sitemap.xml を取得できませんでした。'];
        }

        if (! $response->successful()) {
            return ['status' => 'failed', 'message' => 'sitemap.xml の取得に失敗しました（HTTP '.$response->status().'）。'];
        }

        $result = $this->reader->read($response->body());

        if (! $result->valid) {
            return [
                'status' => 'invalid',
                'message' => $result->errorMessage ?? 'sitemap.xml の書式が正しくありません。',
            ];
        }

        if (! $result->index) {
            return ['status' => 'ok', 'locs' => $result->locs];
        }

        /** @var list<string> $locs */
        $locs = [];

        foreach ($result->locs as $child) {
            $nested = $this->read($child, $depth + 1, $seen);

            if ($nested['status'] !== 'ok') {
                return $nested;
            }

            foreach ($nested['locs'] as $loc) {
                $locs[] = $loc;
            }
        }

        return ['status' => 'ok', 'locs' => $locs];
    }
}
