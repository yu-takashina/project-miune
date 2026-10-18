<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SearchController;
use App\Models\ApiKey;
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

class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.key' => 'test-key',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);
        Http::preventStrayRequests();
        $this->apiKey = ApiKey::factory()->create();
        $this->withToken($this->apiKey->token);
    }

    public function test_a_request_without_an_api_key_is_rejected(): void
    {
        $this->fakeEmbedding($this->vector(1.0));
        $this->actingAs(User::factory()->create());
        unset($this->defaultHeaders['Authorization']);

        $this->getJson('/api/search?q=梅雨')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'APIキーが正しくありません。']);

        $this->assertSame(0, $this->embeddingCalls());
        $this->assertSame(0, SearchTerm::query()->count());
        $this->assertNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_an_unknown_api_key_is_rejected(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->withToken(str_repeat('a', 32))
            ->getJson('/api/search?q=梅雨')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'APIキーが正しくありません。']);

        $this->assertSame(0, $this->embeddingCalls());
        $this->assertNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_an_api_key_with_different_letter_case_is_rejected(): void
    {
        $this->fakeEmbedding($this->vector(1.0));
        $apiKey = ApiKey::factory()->create([
            'token' => 'CaseSensitive+Key-with_symbols01',
        ]);

        $this->withToken('casesensitive+key-with_symbols01')
            ->getJson('/api/search?q=梅雨')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'APIキーが正しくありません。']);

        $this->assertSame(0, $this->embeddingCalls());
        $this->assertNull($apiKey->refresh()->last_used_at);
    }

    public function test_results_are_the_nearest_ten_pages(): void
    {
        $sitemap = Sitemap::factory()->create();
        $close = $this->page($sitemap, 'https://example.com/close');
        $far = $this->page($sitemap, 'https://example.com/far');
        $off = $this->page($sitemap, 'https://example.com/off', ['searchable' => false]);
        $vector = $this->vector(1.0);
        $this->insertEmbedding($close, $vector);
        $this->insertEmbedding($close, $this->vector(0.2, 0.8));
        $this->insertEmbedding($far, $this->vector(0.0, 1.0));
        $this->insertEmbedding($off, $vector);

        $urls = [];

        for ($index = 1; $index <= 9; $index++) {
            $url = 'https://example.com/page-'.$index;
            $this->insertEmbedding($this->page($sitemap, $url), $this->vector(0.0, 1.0));
            $urls[] = $url;
        }

        $this->fakeEmbedding($vector);

        $this->getJson('/api/search?q=近い')
            ->assertOk()
            ->assertExactJson([
                ['url' => 'https://example.com/close', 'article_text' => '採用本文 https://example.com/close'],
                ['url' => 'https://example.com/far', 'article_text' => '採用本文 https://example.com/far'],
                ...array_map(
                    fn (string $url): array => ['url' => $url, 'article_text' => '採用本文 '.$url],
                    array_slice($urls, 0, 8),
                ),
            ]);

        $this->assertSame(1, SearchTerm::query()->count());
        $term = SearchTerm::query()->first();
        $this->assertInstanceOf(SearchTerm::class, $term);
        $this->assertSame('近い', $term->term);
        $this->assertSame(hash('sha256', '近い'), $term->term_sha256);
        $this->assertSame('text-embedding-3-small', $term->model);
        $this->assertNotNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_the_same_term_is_embedded_once(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->getJson('/api/search?q=梅雨')->assertOk();
        $this->getJson('/api/search?q=%20%20%E6%A2%85%E9%9B%A8%20')->assertOk();

        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, SearchTerm::query()->count());
    }

    public function test_a_failed_embedding_is_not_stored(): void
    {
        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response(['error' => ['message' => 'down']], 500),
        ]);

        $this->getJson('/api/search?q=梅雨')
            ->assertStatus(503)
            ->assertExactJson(['message' => '埋め込み API が失敗しました。']);

        $this->assertSame(0, SearchTerm::query()->count());
        $this->assertNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_a_missing_api_key_is_reported(): void
    {
        config(['services.openai.key' => null]);

        $this->getJson('/api/search?q=梅雨')
            ->assertStatus(503)
            ->assertExactJson(['message' => 'OPENAI_API_KEY が未設定です。']);

        $this->assertSame(0, SearchTerm::query()->count());
        $this->assertNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_a_term_longer_than_the_limit_is_not_embedded(): void
    {
        $this->getJson('/api/search?q='.urlencode(str_repeat('あ', 201)))
            ->assertStatus(422)
            ->assertExactJson(['message' => '検索語は200文字以内で入力してください。']);

        $this->assertSame(0, $this->embeddingCalls());
        $this->assertSame(0, SearchTerm::query()->count());
        $this->assertNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_a_term_at_the_limit_is_embedded(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->getJson('/api/search?q='.urlencode(str_repeat('あ', 200)))
            ->assertOk()
            ->assertExactJson([]);

        $this->assertSame(1, $this->embeddingCalls());
    }

    public function test_an_empty_query_does_not_embed(): void
    {
        $this->getJson('/api/search?q=%20%20')
            ->assertStatus(422)
            ->assertExactJson(['message' => '検索語を入力してください。']);

        $this->assertSame(0, SearchTerm::query()->count());
        $this->assertSame(0, $this->embeddingCalls());
        $this->assertNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_invalid_queries_do_not_use_the_embedding_limit(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        for ($index = 0; $index < 61; $index++) {
            $this->getJson('/api/search')->assertStatus(422);
        }

        $this->getJson('/api/search?q=梅雨')->assertOk();

        $this->assertSame(1, $this->embeddingCalls());
    }

    public function test_a_stored_term_ignores_the_limits(): void
    {
        $sitemap = Sitemap::factory()->create();
        $page = $this->page($sitemap, 'https://example.com/rain', [
            'article_text' => '梅雨の話',
        ]);
        $this->insertEmbedding($page, $this->vector(1.0));
        $this->fakeEmbedding($this->vector(1.0));

        $this->getJson('/api/search?q=梅雨')->assertOk();
        $this->fillLimit(SearchController::EMBEDDING_LIMIT_KEY, SearchController::EMBEDDINGS_PER_MINUTE);
        $this->fillLimit(SearchController::TEXT_LIMIT_KEY, SearchController::TEXT_SEARCHES_PER_MINUTE);

        $this->getJson('/api/search?q=梅雨')
            ->assertOk()
            ->assertExactJson([
                ['url' => 'https://example.com/rain', 'article_text' => '梅雨の話'],
            ]);

        $this->assertSame(1, $this->embeddingCalls());
    }

    public function test_the_next_new_term_after_the_embedding_limit_searches_article_text(): void
    {
        $sitemap = Sitemap::factory()->create();
        $page = $this->page($sitemap, 'https://example.com/rain', [
            'article_text' => '今日は梅雨です',
        ]);
        $this->insertEmbedding($page, $this->vector(1.0));
        $this->fakeEmbedding($this->vector(1.0));
        $this->fillLimit(SearchController::EMBEDDING_LIMIT_KEY, SearchController::EMBEDDINGS_PER_MINUTE - 1);

        $this->getJson('/api/search?q=一')->assertOk();

        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, SearchTerm::query()->count());

        $other = ApiKey::factory()->create();

        $this->withToken($other->token)
            ->getJson('/api/search?q=梅雨')
            ->assertOk()
            ->assertExactJson([
                ['url' => 'https://example.com/rain', 'article_text' => '今日は梅雨です'],
            ]);
        $this->withToken($other->token)
            ->getJson('/api/search?q=梅雨')
            ->assertOk()
            ->assertExactJson([
                ['url' => 'https://example.com/rain', 'article_text' => '今日は梅雨です'],
            ]);

        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, SearchTerm::query()->count());
        $this->assertNotNull($other->refresh()->last_used_at);
    }

    public function test_text_search_uses_the_same_page_filters(): void
    {
        $sitemap = Sitemap::factory()->create();
        $this->page($sitemap, 'https://example.com/rain', [
            'article_text' => '今日は梅雨です',
        ]);
        $this->page($sitemap, 'https://example.com/unknown', [
            'article_text' => '梅雨明け',
            'http_status' => null,
        ]);
        $this->page($sitemap, 'https://example.com/sun', [
            'article_text' => '快晴',
        ]);
        $this->page($sitemap, 'https://example.com/hidden', [
            'article_text' => '梅雨の非公開',
            'searchable' => false,
        ]);
        $this->page($sitemap, 'https://example.com/unlisted', [
            'article_text' => '梅雨の掲載終了',
            'unlisted_at' => '2026-04-02 00:00:00',
        ]);
        $this->page($sitemap, 'https://example.com/missing', [
            'article_text' => '梅雨の不明',
            'http_status' => 404,
        ]);
        $this->page($sitemap, 'https://example.com/nobody', [
            'article_text' => '梅雨の本文なし',
            'body' => null,
            'body_sha256' => null,
        ]);
        $this->page(Sitemap::factory()->create(), 'https://example.com/rain', [
            'article_text' => '別サイトの梅雨',
        ]);
        $this->fakeEmbedding($this->vector(1.0));
        $this->fillLimit(SearchController::EMBEDDING_LIMIT_KEY, SearchController::EMBEDDINGS_PER_MINUTE);

        $this->getJson('/api/search?q=梅雨')
            ->assertOk()
            ->assertExactJson([
                ['url' => 'https://example.com/rain', 'article_text' => '今日は梅雨です'],
                ['url' => 'https://example.com/unknown', 'article_text' => '梅雨明け'],
            ]);

        $this->assertSame(0, $this->embeddingCalls());
        $this->assertSame(0, SearchTerm::query()->count());
    }

    public function test_text_search_matches_the_literal_term(): void
    {
        $sitemap = Sitemap::factory()->create();
        $this->page($sitemap, 'https://example.com/literal', [
            'article_text' => '価格は100%_offです',
        ]);
        $this->page($sitemap, 'https://example.com/wild', [
            'article_text' => '価格は100aboffです',
        ]);
        $this->page($sitemap, 'https://example.com/slash', [
            'article_text' => 'path\\to',
        ]);
        $this->page($sitemap, 'https://example.com/slash-wild', [
            'article_text' => 'pathXto',
        ]);
        $this->fakeEmbedding($this->vector(1.0));
        $this->fillLimit(SearchController::EMBEDDING_LIMIT_KEY, SearchController::EMBEDDINGS_PER_MINUTE);

        $this->getJson('/api/search?q='.urlencode('100%_off'))
            ->assertOk()
            ->assertExactJson([
                ['url' => 'https://example.com/literal', 'article_text' => '価格は100%_offです'],
            ]);

        $this->getJson('/api/search?q='.urlencode('path\\to'))
            ->assertOk()
            ->assertExactJson([
                ['url' => 'https://example.com/slash', 'article_text' => 'path\\to'],
            ]);

        $this->assertSame(0, $this->embeddingCalls());
    }

    public function test_text_search_returns_ten_pages_in_id_order(): void
    {
        $sitemap = Sitemap::factory()->create();
        $expected = [];

        for ($index = 1; $index <= 11; $index++) {
            $url = 'https://example.com/page-'.$index;
            $this->page($sitemap, $url, [
                'article_text' => '梅雨 '.$index,
            ]);

            if ($index <= SearchController::LIMIT) {
                $expected[] = ['url' => $url, 'article_text' => '梅雨 '.$index];
            }
        }

        $this->fakeEmbedding($this->vector(1.0));
        $this->fillLimit(SearchController::EMBEDDING_LIMIT_KEY, SearchController::EMBEDDINGS_PER_MINUTE);

        $this->getJson('/api/search?q=梅雨')
            ->assertOk()
            ->assertExactJson($expected);
    }

    public function test_text_search_stops_when_its_limit_is_reached(): void
    {
        $this->page(Sitemap::factory()->create(), 'https://example.com/rain', [
            'article_text' => '梅雨の話',
        ]);
        $this->fakeEmbedding($this->vector(1.0));
        $this->fillLimit(SearchController::EMBEDDING_LIMIT_KEY, SearchController::EMBEDDINGS_PER_MINUTE);
        $this->fillLimit(SearchController::TEXT_LIMIT_KEY, SearchController::TEXT_SEARCHES_PER_MINUTE);

        $this->getJson('/api/search?q=新しい語')
            ->assertStatus(429)
            ->assertExactJson(['message' => 'リクエストが多すぎます。'])
            ->assertHeader('Retry-After');

        $this->assertSame(0, $this->embeddingCalls());
        $this->assertSame(0, SearchTerm::query()->count());
        $this->assertNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_the_api_does_not_start_a_session(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $response = $this->getJson('/api/search?q=梅雨')->assertOk();

        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['session.store']->isStarted());
    }

    public function test_a_query_with_no_vectors_returns_an_empty_list(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->getJson('/api/search?q=該当なし')
            ->assertOk()
            ->assertExactJson([]);

        $this->assertNotNull($this->apiKey->refresh()->last_used_at);
    }

    public function test_a_guest_with_a_valid_api_key_can_search(): void
    {
        $this->fakeEmbedding($this->vector(1.0));

        $this->getJson('/api/search?q=梅雨')
            ->assertOk()
            ->assertExactJson([]);

        $this->assertNotNull($this->apiKey->refresh()->last_used_at);
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

    private function fillLimit(string $key, int $amount): void
    {
        RateLimiter::increment($key, 60, $amount);
    }

    private function embeddingCalls(): int
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => $pair[0] instanceof Request && $pair[0]->url() === 'https://api.openai.com/v1/embeddings')
            ->count();
    }
}
