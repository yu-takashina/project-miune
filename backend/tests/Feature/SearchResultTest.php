<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SearchController as ApiSearchController;
use App\Models\SearchTerm;
use App\Models\Sitemap;
use App\Models\SitemapPage;
use App\Models\User;
use App\Services\OpenAiEmbeddings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SearchResultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'services.openai.key' => 'test-key',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_guests_cannot_embed_a_term(): void
    {
        $this->fakeEmbedding($this->vector(1.0));
        auth()->logout();

        $this->get('/manager/search?q=梅雨')->assertRedirect('/');

        $this->assertSame(0, $this->embeddingCalls());
        $this->assertSame(0, SearchTerm::query()->count());
    }

    public function test_the_same_term_is_embedded_once(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->get('/manager/search?q=梅雨')->assertOk();
        $this->get('/manager/search?q=%20%20%E6%A2%85%E9%9B%A8%20')->assertOk();

        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, SearchTerm::query()->count());
        $term = SearchTerm::query()->first();
        $this->assertInstanceOf(SearchTerm::class, $term);
        $this->assertSame('梅雨', $term->term);
        $this->assertSame(hash('sha256', '梅雨'), $term->term_sha256);
        $this->assertSame('text-embedding-3-small', $term->model);
    }

    public function test_a_different_model_is_embedded_again(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->get('/manager/search?q=梅雨')->assertOk();
        config(['services.openai.embedding_model' => 'text-embedding-3-large']);
        $this->get('/manager/search?q=梅雨')->assertOk();

        $this->assertSame(2, $this->embeddingCalls());
        $this->assertSame(2, SearchTerm::query()->count());
    }

    public function test_a_failed_embedding_is_not_stored(): void
    {
        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response(['error' => ['message' => 'down']], 500),
        ]);

        $this->get('/manager/search?q=梅雨')
            ->assertOk()
            ->assertSee('埋め込み API が失敗しました。')
            ->assertDontSee('一致する URL はありません。');

        $this->assertSame(0, SearchTerm::query()->count());
    }

    public function test_a_missing_api_key_is_shown_to_the_manager(): void
    {
        config(['services.openai.key' => null]);

        $this->get('/manager/search?q=梅雨')
            ->assertOk()
            ->assertSee('OPENAI_API_KEY が未設定です。');

        $this->assertSame(0, SearchTerm::query()->count());
    }

    public function test_a_term_longer_than_the_limit_is_not_embedded(): void
    {
        $this->get('/manager/search?q='.urlencode(str_repeat('あ', 201)))
            ->assertOk()
            ->assertSee('検索語は200文字以内で入力してください。');

        $this->assertSame(0, $this->embeddingCalls());
        $this->assertSame(0, SearchTerm::query()->count());
    }

    public function test_a_term_at_the_limit_is_embedded(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->get('/manager/search?q='.urlencode(str_repeat('あ', 200)))
            ->assertOk()
            ->assertDontSee('検索語は200文字以内で入力してください。');

        $this->assertSame(1, $this->embeddingCalls());
    }

    public function test_searches_are_limited_per_minute(): void
    {
        for ($index = 0; $index < 30; $index++) {
            $this->get('/manager/search')->assertOk();
        }

        $this->get('/manager/search')->assertStatus(429);
    }

    public function test_manager_search_embeds_when_the_api_limit_is_exhausted(): void
    {
        $this->fakeEmbedding($this->vector(1.0));
        RateLimiter::increment(
            ApiSearchController::EMBEDDING_LIMIT_KEY,
            60,
            ApiSearchController::EMBEDDINGS_PER_MINUTE,
        );

        $this->get('/manager/search?q=梅雨')->assertOk();

        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, SearchTerm::query()->count());
    }

    public function test_an_empty_query_does_not_embed(): void
    {
        $this->get('/manager/search?q=%20%20')
            ->assertOk()
            ->assertSee('<h1 class="text-2xl font-medium">検索</h1>', false)
            ->assertDontSee('一致する URL はありません。');

        $this->assertSame(0, SearchTerm::query()->count());
        $this->assertSame(0, $this->embeddingCalls());
    }

    public function test_results_are_ordered_by_similarity(): void
    {
        $sitemap = Sitemap::factory()->create();
        $close = $this->page($sitemap, 'https://example.com/close');
        $far = $this->page($sitemap, 'https://example.com/far');
        $this->insertEmbedding($close, $this->vector(1.0));
        $this->insertEmbedding($far, $this->vector(0.0, 1.0));
        $this->fakeEmbedding($this->vector(1.0));

        $this->get('/manager/search?q=近い')
            ->assertOk()
            ->assertSeeInOrder([
                'https://example.com/close',
                '1.00',
                'https://example.com/far',
                '0.00',
            ])
            ->assertSee('関連度')
            ->assertDontSee('ステータス')
            ->assertDontSee('容量')
            ->assertDontSee('巡回日時')
            ->assertDontSee('今すぐ巡回')
            ->assertDontSee('検索対象');
    }

    public function test_results_skip_pages_outside_the_search_scope(): void
    {
        $sitemap = Sitemap::factory()->create();
        $listed = $this->page($sitemap, 'https://example.com/listed');
        $off = $this->page($sitemap, 'https://example.com/off', ['searchable' => false]);
        $gone = $this->page($sitemap, 'https://example.com/gone', ['unlisted_at' => now()]);
        $stale = $this->page($sitemap, 'https://example.com/stale');
        $empty = $this->page($sitemap, 'https://example.com/empty', ['body' => null, 'body_sha256' => null]);
        $missing = $this->page($sitemap, 'https://example.com/missing', ['http_status' => 404]);
        $unknown = $this->page($sitemap, 'https://example.com/unknown', ['http_status' => null]);
        $failing = $this->page($sitemap, 'https://example.com/failing', ['fetch_failure_count' => 1]);
        $retrying = $this->page($sitemap, 'https://example.com/retrying', [
            'fetch_failure_count' => 1,
            'crawled_at' => now()->subDays(SitemapPage::SEARCH_STALE_DAYS - 1),
        ]);
        $sitemapOff = $this->page(Sitemap::factory()->create(['searchable' => false]), 'https://example.com/sitemap-off');
        $vector = $this->vector(1.0);
        $this->insertEmbedding($failing, $vector);
        $this->insertEmbedding($retrying, $vector);
        $this->insertEmbedding($listed, $vector);
        $this->insertEmbedding($off, $vector);
        $this->insertEmbedding($gone, $vector);
        $this->insertEmbedding($stale, $vector, hash('sha256', '古い本文'));
        $this->insertEmbedding($empty, $vector);
        $this->insertEmbedding($missing, $vector);
        $this->insertEmbedding($unknown, $vector);
        $this->insertEmbedding($sitemapOff, $vector);
        $this->fakeEmbedding($vector);

        $this->get('/manager/search?q=対象')
            ->assertOk()
            ->assertSee('https://example.com/listed')
            ->assertSee('https://example.com/unknown')
            ->assertSee('https://example.com/retrying')
            ->assertDontSee('https://example.com/failing')
            ->assertDontSee('https://example.com/off')
            ->assertDontSee('https://example.com/gone')
            ->assertDontSee('https://example.com/stale')
            ->assertDontSee('https://example.com/empty')
            ->assertDontSee('https://example.com/missing')
            ->assertDontSee('https://example.com/sitemap-off');
    }

    public function test_the_same_url_on_another_sitemap_is_shown_once(): void
    {
        $first = Sitemap::factory()->create();
        $second = Sitemap::factory()->create();
        $weaker = $this->page($first, 'https://example.com/shared');
        $closer = $this->page($second, 'https://example.com/shared');
        $other = $this->page($first, 'https://example.com/other');
        $this->insertEmbedding($weaker, $this->vector(0.0, 1.0));
        $this->insertEmbedding($closer, $this->vector(1.0));
        $this->insertEmbedding($other, $this->vector(0.0, 1.0));
        $this->fakeEmbedding($this->vector(1.0));

        $content = $this->get('/manager/search?q=同一')->assertOk()->getContent();

        $this->assertIsString($content);
        $this->assertSame(1, substr_count($content, 'break-all">https://example.com/shared</span>'));
        $this->assertSame(1, substr_count($content, 'break-all">https://example.com/other</span>'));
        $this->assertLessThan(
            strpos($content, 'break-all">https://example.com/other</span>'),
            strpos($content, 'break-all">https://example.com/shared</span>'),
        );
        $this->assertLessThan(
            strpos($content, 'break-all">https://example.com/other</span>'),
            strpos($content, '>1.00</td>'),
        );
    }

    public function test_a_repeated_url_does_not_take_the_place_of_another_page(): void
    {
        $first = Sitemap::factory()->create();
        $second = Sitemap::factory()->create();
        $vector = $this->vector(1.0);
        $urls = [];

        for ($index = 1; $index <= 19; $index++) {
            $url = 'https://example.com/page-'.$index;
            $this->insertEmbedding($this->page($first, $url), $vector);
            $urls[] = $url;
        }

        $this->insertEmbedding($this->page($second, $urls[0]), $vector);
        $tail = 'https://example.com/page-20';
        $this->insertEmbedding($this->page($first, $tail), $vector);
        $this->fakeEmbedding($vector);

        $content = $this->get('/manager/search?q=枠')->assertOk()->getContent();
        $this->assertIsString($content);

        foreach ($urls as $url) {
            $this->assertSame(1, substr_count($content, 'break-all">'.$url.'</span>'));
        }

        $this->assertSame(1, substr_count($content, 'break-all">'.$tail.'</span>'));
    }

    public function test_duplicate_embeddings_for_one_page_are_shown_once(): void
    {
        $sitemap = Sitemap::factory()->create();
        $close = $this->page($sitemap, 'https://example.com/close');
        $far = $this->page($sitemap, 'https://example.com/far');
        $this->insertEmbedding($close, $this->vector(1.0));
        $this->insertEmbedding($close, $this->vector(0.2, 0.8));
        $this->insertEmbedding($far, $this->vector(0.0, 1.0));
        $this->fakeEmbedding($this->vector(1.0));

        $content = $this->get('/manager/search?q=重複')->assertOk()->getContent();

        $this->assertIsString($content);
        $this->assertSame(1, substr_count($content, 'break-all">https://example.com/close</span>'));
        $this->assertSame(1, substr_count($content, 'break-all">https://example.com/far</span>'));
        $this->assertLessThan(
            strpos($content, 'break-all">https://example.com/far</span>'),
            strpos($content, 'break-all">https://example.com/close</span>'),
        );
    }

    public function test_results_stop_at_twenty_pages(): void
    {
        $sitemap = Sitemap::factory()->create();
        $vector = $this->vector(1.0);
        $urls = [];

        for ($index = 1; $index <= 21; $index++) {
            $url = 'https://example.com/page-'.$index;
            $this->insertEmbedding($this->page($sitemap, $url), $vector);
            $urls[] = $url;
        }

        $this->fakeEmbedding($vector);
        $content = $this->get('/manager/search?q=上限')->assertOk()->getContent();
        $this->assertIsString($content);

        foreach (array_slice($urls, 0, 20) as $url) {
            $this->assertStringContainsString($url, $content);
        }

        $this->assertStringNotContainsString($urls[20], $content);
    }

    public function test_a_query_with_no_vectors_says_there_are_no_matches(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->get('/manager/search?q=該当なし')
            ->assertOk()
            ->assertSee('一致する URL はありません。')
            ->assertSee('<h1 class="text-2xl font-medium">「該当なし」の検索結果</h1>', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function page(Sitemap $sitemap, string $url, array $overrides = []): SitemapPage
    {
        return SitemapPage::query()->create(array_merge([
            'sitemap_id' => $sitemap->id,
            'url' => $url,
            'searchable' => true,
            'body' => '本文',
            'body_sha256' => hash('sha256', '本文'),
            'article_text' => '採用本文 '.$url,
            'http_status' => 200,
            'response_bytes' => 128,
            'crawled_at' => '2026-04-01 12:00:00',
        ], $overrides));
    }

    /**
     * @param  list<float>  $vector
     */
    private function insertEmbedding(SitemapPage $page, array $vector, ?string $hash = null): void
    {
        DB::table('sitemap_page_embeddings')->insert([
            'sitemap_page_id' => $page->id,
            'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
            'article_text_sha256' => $hash ?? $page->article_text_sha256,
            'embedded_at' => now(),
        ]);
    }

    /**
     * @return list<float>
     */
    private function vector(float $first, float $second = 0.0): array
    {
        $vector = array_fill(0, OpenAiEmbeddings::DIMENSIONS, 0.0);
        $vector[0] = $first;
        $vector[1] = $second;

        return $vector;
    }

    /**
     * @param  list<float>  $vector
     */
    private function fakeEmbedding(array $vector): void
    {
        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => $vector, 'index' => 0],
                ],
            ]),
        ]);
    }

    private function embeddingCalls(): int
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => $pair[0] instanceof Request && $pair[0]->url() === 'https://api.openai.com/v1/embeddings')
            ->count();
    }
}
