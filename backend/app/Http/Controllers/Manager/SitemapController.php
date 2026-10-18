<?php

namespace App\Http\Controllers\Manager;

use App\Enums\SitemapStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreSitemapRequest;
use App\Jobs\CrawlSitemapPage;
use App\Jobs\InspectSitemap;
use App\Models\Sitemap;
use App\Models\SitemapPage;
use App\Services\SitemapTreeReader;
use App\Services\SitemapUrlsetReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SitemapController extends Controller
{
    public function index(): View
    {
        $sitemaps = Sitemap::query()
            ->withCount([
                'pages' => function (Builder $query): void {
                    $query->whereNull('unlisted_at');
                },
            ])
            ->orderByDesc('id')
            ->get();

        return view('manager.sitemaps.index', [
            'sitemaps' => $sitemaps,
        ]);
    }

    public function store(StoreSitemapRequest $request): RedirectResponse
    {
        $url = $request->string('url')->toString();
        $collected = (new SitemapTreeReader(new SitemapUrlsetReader))->collect($url);

        if ($collected['status'] === 'too_deep') {
            throw ValidationException::withMessages([
                'url' => $collected['message'],
            ]);
        }

        try {
            $sitemap = Sitemap::query()->create([
                'url' => $url,
                'status' => SitemapStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'url' => 'この URL は登録済みです。',
            ]);
        }

        return redirect()->route('sitemap.index');
    }

    public function show(Request $request, Sitemap $sitemap): View
    {
        $query = $request->string('q')->trim()->toString();
        $searchable = $request->string('searchable')->toString();
        $status = $request->string('status')->toString();
        [$sort, $order] = $this->listSort($request);

        if (! in_array($searchable, ['0', '1'], true)) {
            $searchable = '';
        }

        if (! in_array($status, ['200', '404', 'other'], true)) {
            $status = '';
        }

        $pages = $sitemap->pages()
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where('url', 'like', '%'.addcslashes($query, '%_\\').'%');
            })
            ->when($searchable !== '', function (Builder $builder) use ($searchable): void {
                $builder->where('searchable', $searchable === '1');
            })
            ->when($status === '200', function (Builder $builder): void {
                $builder->where('http_status', 200);
            })
            ->when($status === '404', function (Builder $builder): void {
                $builder->where('http_status', 404);
            })
            ->when($status === 'other', function (Builder $builder): void {
                $builder->whereNotNull('http_status')
                    ->whereNotIn('http_status', [200, 404]);
            });

        match ($sort.':'.$order) {
            'url:asc' => $pages->orderBy('url'),
            'url:desc' => $pages->orderByDesc('url'),
            'size:asc' => $pages->orderByRaw('response_bytes is null')->orderBy('response_bytes'),
            'size:desc' => $pages->orderByRaw('response_bytes is null')->orderByDesc('response_bytes'),
            'crawled_at:asc' => $pages->orderByRaw('crawled_at is null')->orderBy('crawled_at'),
            'crawled_at:desc' => $pages->orderByRaw('crawled_at is null')->orderByDesc('crawled_at'),
            default => $pages->orderBy('url'),
        };

        $pages = $pages
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $filters = [];

        if ($query !== '') {
            $filters['q'] = $query;
        }

        if ($searchable !== '') {
            $filters['searchable'] = $searchable;
        }

        if ($status !== '') {
            $filters['status'] = $status;
        }

        return view('manager.sitemaps.show', [
            'sitemap' => $sitemap,
            'pages' => $pages,
            'query' => $query,
            'searchable' => $searchable,
            'status' => $status,
            'sort' => $sort,
            'order' => $order,
            'filters' => $filters,
        ]);
    }

    public function updateSearchable(Request $request, Sitemap $sitemap, SitemapPage $page): JsonResponse
    {
        if ((int) $page->sitemap_id !== $sitemap->id) {
            abort(404);
        }

        $request->validate([
            'searchable' => ['required', 'boolean'],
        ]);

        $page->searchable = $request->boolean('searchable');
        $page->save();

        return response()->json([
            'searchable' => $page->searchable,
        ]);
    }

    public function crawl(Sitemap $sitemap, SitemapPage $page): JsonResponse
    {
        if ((int) $page->sitemap_id !== $sitemap->id) {
            abort(404);
        }

        CrawlSitemapPage::dispatchSync($page->id);
        $page->refresh();

        return response()->json([
            'http_status' => $page->http_status,
            'response_size' => $page->formattedResponseSize(),
            'error_message' => $page->error_message,
            'crawled_at' => $page->crawled_at?->format('Y-m-d H:i'),
        ]);
    }

    public function crawlNow(Sitemap $sitemap): JsonResponse
    {
        (new InspectSitemap($sitemap->id, true))->handle(new SitemapUrlsetReader);
        $sitemap->refresh();

        return response()->json([
            'status_label' => $sitemap->status->label(),
            'error_message' => $sitemap->error_message,
            'pages_count' => $sitemap->pages()->whereNull('unlisted_at')->count(),
            'crawled_at' => $sitemap->crawled_at?->format('Y-m-d H:i'),
        ]);
    }

    public function updateAbsenceLimit(Request $request, Sitemap $sitemap): JsonResponse
    {
        $request->validate([
            'absence_limit' => ['present', 'nullable', 'integer', 'between:1,5'],
        ]);

        $sitemap->absence_limit = $this->absenceLimit($request->input('absence_limit'));
        $sitemap->save();

        return response()->json([
            'absence_limit' => $sitemap->absence_limit,
        ]);
    }

    public function destroy(Sitemap $sitemap): RedirectResponse
    {
        $sitemap->delete();

        return redirect()->route('sitemap.index');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function listSort(Request $request): array
    {
        $sort = $request->string('sort')->toString();
        $order = $request->string('order')->toString();

        if (! in_array($sort, ['url', 'size', 'crawled_at'], true) || ! in_array($order, ['asc', 'desc'], true)) {
            return ['url', 'asc'];
        }

        return [$sort, $order];
    }

    private function absenceLimit(mixed $limit): ?int
    {
        if (is_int($limit)) {
            return $limit;
        }

        if (is_string($limit) && ctype_digit($limit)) {
            return (int) $limit;
        }

        return null;
    }
}
