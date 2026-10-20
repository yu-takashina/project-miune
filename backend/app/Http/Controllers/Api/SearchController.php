<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SearchController as ManagerSearchController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ApiKey;
use App\Models\SitemapPage;
use App\Services\OpenAiEmbeddingException;
use App\Services\RememberSearchTerm;
use App\Services\SearchEmbeddingLimited;
use App\Services\SearchSitemapPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    public const LIMIT = 10;

    /** 結果に返す本文の先頭の文字数。 */
    public const ARTICLE_TEXT_LENGTH = 500;

    /** 検索API全体で、新規の検索語をベクトル化できる回数。 */
    public const EMBEDDINGS_PER_MINUTE = 60;

    /** ベクトル化の上限を超えたあとの、本文の部分一致の回数。 */
    public const TEXT_SEARCHES_PER_MINUTE = 60;

    public const EMBEDDING_LIMIT_KEY = 'api-search-embedding';

    public const TEXT_LIMIT_KEY = 'api-search-text';

    public function __invoke(Request $request, RememberSearchTerm $terms, SearchSitemapPages $search): JsonResponse
    {
        $apiKey = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);

        if (! $apiKey instanceof ApiKey) {
            return response()->json([
                'message' => 'APIキーが正しくありません。',
            ], 401);
        }

        $term = $request->query('q');
        $query = is_string($term) ? Str::trim($term) : '';

        if ($query === '') {
            return response()->json([
                'message' => '検索語を入力してください。',
            ], 422);
        }

        if (mb_strlen($query) > ManagerSearchController::MAX_TERM_LENGTH) {
            return response()->json([
                'message' => '検索語は'.ManagerSearchController::MAX_TERM_LENGTH.'文字以内で入力してください。',
            ], 422);
        }

        try {
            $vector = $terms->vector(
                $query,
                fn (): bool => $this->take(self::EMBEDDING_LIMIT_KEY, self::EMBEDDINGS_PER_MINUTE),
            );
            $ids = [];

            foreach ($search->nearest($vector, self::LIMIT) as $match) {
                $ids[] = $match['id'];
            }
        } catch (SearchEmbeddingLimited) {
            if (! $this->take(self::TEXT_LIMIT_KEY, self::TEXT_SEARCHES_PER_MINUTE)) {
                return $this->tooManyRequests();
            }

            $ids = $search->containing($query, self::LIMIT);
        } catch (OpenAiEmbeddingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        $apiKey->markUsed();

        return response()->json($this->results($ids));
    }

    /**
     * 判定と加算を分けると、同時に来た別の検索語が上限をすり抜ける。先に足した回数で決める。
     */
    private function take(string $key, int $maximum): bool
    {
        return RateLimiter::increment($key) <= $maximum;
    }

    private function tooManyRequests(): JsonResponse
    {
        return response()->json([
            'message' => 'リクエストが多すぎます。',
        ], 429)->header('Retry-After', (string) RateLimiter::availableIn(self::TEXT_LIMIT_KEY));
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{url: string, article_text: string|null}>
     */
    private function results(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $pages = SitemapPage::query()
            ->whereIn('id', $ids)
            ->get(['id', 'url', 'article_text'])
            ->keyBy('id');
        $results = [];

        foreach ($ids as $id) {
            $page = $pages->get($id);

            if (! $page instanceof SitemapPage) {
                continue;
            }

            $results[] = [
                'url' => $page->url,
                'article_text' => $page->article_text === null
                    ? null
                    : mb_substr($page->article_text, 0, self::ARTICLE_TEXT_LENGTH),
            ];
        }

        return $results;
    }
}
