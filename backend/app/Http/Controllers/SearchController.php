<?php

namespace App\Http\Controllers;

use App\Models\SitemapPage;
use App\Services\OpenAiEmbeddingException;
use App\Services\RememberSearchTerm;
use App\Services\SearchSitemapPages;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public const MAX_TERM_LENGTH = 200;

    public function __invoke(Request $request, RememberSearchTerm $terms, SearchSitemapPages $search): View
    {
        $query = $request->string('q')->trim()->toString();
        /** @var list<array{page: SitemapPage, relevance: float}> $results */
        $results = [];
        $error = null;

        if (mb_strlen($query) > self::MAX_TERM_LENGTH) {
            $error = '検索語は'.self::MAX_TERM_LENGTH.'文字以内で入力してください。';
        } elseif ($query !== '') {
            try {
                $results = $this->results($search->nearest($terms->vector($query)));
            } catch (OpenAiEmbeddingException $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('search', [
            'query' => $query,
            'results' => $results,
            'error' => $error,
        ]);
    }

    /**
     * @param  list<array{id: int, relevance: float}>  $matches
     * @return list<array{page: SitemapPage, relevance: float}>
     */
    private function results(array $matches): array
    {
        if ($matches === []) {
            return [];
        }

        $pages = SitemapPage::query()
            ->whereIn('id', array_column($matches, 'id'))
            ->get(['id', 'url', 'unlisted_at'])
            ->keyBy('id');
        $results = [];

        foreach ($matches as $match) {
            $page = $pages->get($match['id']);

            if ($page instanceof SitemapPage) {
                $results[] = [
                    'page' => $page,
                    'relevance' => $match['relevance'],
                ];
            }
        }

        return $results;
    }
}
