<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SearchController as ManagerSearchController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ApiKey;
use App\Models\SitemapPage;
use App\Services\OpenAiEmbeddingException;
use App\Services\RememberSearchTerm;
use App\Services\SearchSitemapPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public const LIMIT = 10;

    public function __invoke(Request $request, RememberSearchTerm $terms, SearchSitemapPages $search): JsonResponse
    {
        $apiKey = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);

        if (! $apiKey instanceof ApiKey) {
            return response()->json([
                'message' => 'APIキーが正しくありません。',
            ], 401);
        }

        $query = $request->string('q')->trim()->toString();

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
            $matches = $search->nearest($terms->vector($query), self::LIMIT);
        } catch (OpenAiEmbeddingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        $apiKey->markUsed();

        return response()->json($this->results($matches));
    }

    /**
     * @param  list<array{id: int, relevance: float}>  $matches
     * @return list<array{url: string, article_text: string|null}>
     */
    private function results(array $matches): array
    {
        if ($matches === []) {
            return [];
        }

        $pages = SitemapPage::query()->whereIn('id', array_column($matches, 'id'))->get()->keyBy('id');
        $results = [];

        foreach ($matches as $match) {
            $page = $pages->get($match['id']);

            if (! $page instanceof SitemapPage) {
                continue;
            }

            $results[] = [
                'url' => $page->url,
                'article_text' => $page->article_text,
            ];
        }

        return $results;
    }
}
