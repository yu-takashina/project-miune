<?php

namespace Tests\Feature;

use App\Enums\CrawlErrorKind;
use App\Jobs\CrawlSitemapPage;
use App\Models\CrawlErrorLog;
use App\Models\Sitemap;
use App\Models\SitemapPage;
use App\Models\SitemapPageEmbedding;
use App\Services\EmbedPendingSitemapPages;
use App\Services\ExtractSitemapPage;
use App\Services\OpenAiEmbeddings;
use App\Services\ReadabilityTextExtractor;
use App\Services\TrafilaturaTextExtractor;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitemapEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.key' => 'test-key',
            'services.openai.embedding_model' => 'text-embedding-3-small',
        ]);
        Http::preventStrayRequests();
    }

    public function test_a_changed_article_is_embedded_by_the_embed_step_not_the_crawl(): void
    {
        $this->fakeCrawl('本文A', '本文A');
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);

        $this->assertSame('本文A', $page->refresh()->articleText());
        $this->assertSame(0, $this->embeddingCalls());
        $this->assertSame(0, $page->embeddings()->count());

        $this->embed();

        $page->refresh();
        $this->assertNull($page->embedding_error);
        $this->assertNull($page->embedding_failed_sha256);
        $this->assertSame(1, $page->embeddings()->count());
        $embedding = $page->embeddings()->first();
        $this->assertInstanceOf(SitemapPageEmbedding::class, $embedding);
        $this->assertSame($page->article_text_sha256, $embedding->article_text_sha256);
        $stored = json_decode($embedding->embedding, true);
        $this->assertIsArray($stored);
        $this->assertCount(OpenAiEmbeddings::DIMENSIONS, $stored);
        $this->assertSame(1, $this->embeddingCalls());
    }

    public function test_the_same_body_is_not_embedded_again(): void
    {
        $this->fakeCrawl('本文A', '本文A');
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);
        $this->embed();
        CrawlSitemapPage::dispatchSync($page->id);
        $this->embed();

        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, $page->embeddings()->count());
    }

    public function test_the_same_article_hash_is_not_embedded_again(): void
    {
        Http::fake([
            'https://example.com/note' => Http::sequence()
                ->push($this->html('one'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                ->push($this->html('two'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'https://api.openai.com/v1/embeddings' => $this->embeddingResponse(),
        ]);
        $this->bindExtractor('同じ本文', '同じ本文');
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);
        $this->embed();
        CrawlSitemapPage::dispatchSync($page->id);
        $this->embed();

        $page->refresh();
        $this->assertSame('同じ本文', $page->articleText());
        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, $page->embeddings()->count());
    }

    public function test_an_article_changed_outside_the_crawl_is_embedded_again(): void
    {
        $this->fakeCrawl('本文A', '本文A');
        $page = $this->page();
        CrawlSitemapPage::dispatchSync($page->id);
        $this->embed();

        $page->refresh()->update(['article_text' => '本文B']);
        $this->embed();

        $page->refresh();
        $this->assertSame(2, $this->embeddingCalls());
        $this->assertSame(1, $page->embeddings()->count());
        $this->assertTrue($page->hasEmbeddingForArticle());
    }

    public function test_clearing_the_article_deletes_every_vector(): void
    {
        $this->fakeCrawl('本文A', '本文A');
        $page = $this->page();
        CrawlSitemapPage::dispatchSync($page->id);
        $this->insertEmbedding($page->refresh(), '[0]');

        $page->update(['article_text' => '']);

        $this->assertSame(0, $page->embeddings()->count());
        $this->assertNull($page->refresh()->article_text_sha256);
    }

    public function test_a_temporary_failure_doubles_the_wait_before_the_next_try(): void
    {
        $this->freezeSecond();
        Http::fake([
            'https://example.com/note' => $this->htmlResponse(),
            'https://api.openai.com/v1/embeddings' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push([
                    'data' => [
                        ['embedding' => array_fill(0, OpenAiEmbeddings::DIMENSIONS, 0.5), 'index' => 0],
                    ],
                ]),
        ]);
        $this->bindExtractor('本文A', '本文A');
        $page = $this->page();
        CrawlSitemapPage::dispatchSync($page->id);

        $this->embed();

        $page->refresh();
        $this->assertSame('埋め込み API が失敗しました。', $page->embedding_error);
        $this->assertNull($page->embedding_failed_sha256);
        $this->assertSame(1, $page->embedding_failure_count);
        $this->assertTrue(now()->addMinute()->equalTo($page->embedding_retry_at));
        $log = CrawlErrorLog::query()->sole();
        $this->assertSame(CrawlErrorKind::Embedding, $log->kind);
        $this->assertSame($page->id, $log->sitemap_page_id);

        $this->embed();
        $this->assertSame(1, $this->embeddingCalls());

        $this->travel(1)->minutes();
        $this->embed();

        $page->refresh();
        $this->assertSame(2, $page->embedding_failure_count);
        $this->assertTrue(now()->addMinutes(2)->equalTo($page->embedding_retry_at));

        $this->travel(2)->minutes();
        $this->embed();

        $page->refresh();
        $this->assertSame(3, $this->embeddingCalls());
        $this->assertSame(1, $page->embeddings()->count());
        $this->assertSame(0, $page->embedding_failure_count);
        $this->assertNull($page->embedding_retry_at);
        $this->assertNull($page->embedding_error);
    }

    public function test_a_context_length_error_records_the_failed_hash(): void
    {
        Http::fake([
            'https://example.com/note' => $this->htmlResponse(),
            'https://api.openai.com/v1/embeddings' => Http::response([
                'error' => [
                    'message' => "This model's maximum context length is 8192 tokens.",
                    'code' => 'context_length_exceeded',
                ],
            ], 400),
        ]);
        $this->bindExtractor('長すぎる本文', '長すぎる本文');
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);
        $this->embed();
        $this->embed();

        $page->refresh();
        $this->assertSame('長すぎる本文', $page->articleText());
        $this->assertSame('本文が長すぎてベクトル化できません。', $page->embedding_error);
        $this->assertSame(hash('sha256', '長すぎる本文'), $page->embedding_failed_sha256);
        $this->assertSame(0, $page->embedding_failure_count);
        $this->assertNull($page->embedding_retry_at);
        $this->assertSame(0, $page->embeddings()->count());
        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, CrawlErrorLog::query()->count());
    }

    public function test_only_the_first_four_thousand_characters_are_sent(): void
    {
        $text = str_repeat('あ', OpenAiEmbeddings::MAX_INPUT_CHARACTERS + 1);
        $this->fakeCrawl($text, $text);
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);
        $this->embed();

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.openai.com/v1/embeddings') {
                return false;
            }

            $input = $request->data()['input'] ?? null;

            return is_string($input) && mb_strlen($input) === OpenAiEmbeddings::MAX_INPUT_CHARACTERS;
        });
    }

    public function test_a_missing_api_key_pauses_the_embed_step(): void
    {
        $this->freezeSecond();
        config(['services.openai.key' => null]);
        $this->fakeCrawl('本文A', '本文A');
        $page = $this->page();
        CrawlSitemapPage::dispatchSync($page->id);

        $this->embed();
        $this->embed();

        $page->refresh();
        $this->assertSame('本文A', $page->articleText());
        $this->assertNull($page->embedding_error);
        $this->assertSame(0, $page->embedding_failure_count);
        $this->assertSame(0, $this->embeddingCalls());
        $log = CrawlErrorLog::query()->sole();
        $this->assertSame(CrawlErrorKind::Embedding, $log->kind);
        $this->assertNull($log->sitemap_page_id);
        $this->assertSame('OPENAI_API_KEY が未設定です。', $log->message);
    }

    public function test_an_authentication_failure_pauses_the_embed_step_with_a_doubling_wait(): void
    {
        $this->freezeSecond();
        Http::fake([
            'https://example.com/note' => $this->htmlResponse(),
            'https://api.openai.com/v1/embeddings' => Http::sequence()
                ->push(['error' => ['message' => 'invalid api key']], 401)
                ->push(['error' => ['message' => 'invalid api key']], 401)
                ->push([
                    'data' => [
                        ['embedding' => array_fill(0, OpenAiEmbeddings::DIMENSIONS, 0.5), 'index' => 0],
                    ],
                ]),
        ]);
        $this->bindExtractor('本文A', '本文A');
        $page = $this->page();
        CrawlSitemapPage::dispatchSync($page->id);

        $this->embed();
        $this->embed();

        $page->refresh();
        $this->assertSame('本文A', $page->articleText());
        $this->assertNull($page->embedding_error);
        $this->assertNull($page->embedding_failed_sha256);
        $this->assertSame(0, $page->embedding_failure_count);
        $this->assertSame(1, $this->embeddingCalls());

        $this->travel(1)->minutes();
        $this->embed();
        $this->travel(1)->minutes();
        $this->embed();

        $this->assertSame(2, $this->embeddingCalls());

        $this->travel(1)->minutes();
        $this->embed();

        $this->assertSame(3, $this->embeddingCalls());
        $this->assertSame(1, $page->embeddings()->count());
        $this->assertSame(2, CrawlErrorLog::query()->count());
    }

    private function embed(): void
    {
        (new EmbedPendingSitemapPages)->run();
    }

    private function page(): SitemapPage
    {
        $sitemap = Sitemap::factory()->create();

        return SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/note',
        ]);
    }

    private function fakeCrawl(string $readability, string $trafilatura): void
    {
        Http::fake([
            'https://example.com/note' => $this->htmlResponse(),
            'https://api.openai.com/v1/embeddings' => $this->embeddingResponse(),
        ]);
        $this->bindExtractor($readability, $trafilatura);
    }

    private function htmlResponse(): callable
    {
        return fn (): PromiseInterface => Http::response($this->html('one'), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function bindExtractor(string $readabilityText, string $trafilaturaText): void
    {
        $readability = $this->createMock(ReadabilityTextExtractor::class);
        $readability->method('extract')->willReturn($readabilityText);
        $trafilatura = $this->createMock(TrafilaturaTextExtractor::class);
        $trafilatura->method('extract')->willReturn($trafilaturaText);

        $this->app->instance(ExtractSitemapPage::class, new ExtractSitemapPage(
            readability: $readability,
            trafilatura: $trafilatura,
        ));
    }

    private function insertEmbedding(SitemapPage $page, string $embedding): void
    {
        SitemapPageEmbedding::query()->getConnection()->table('sitemap_page_embeddings')->insert([
            'sitemap_page_id' => $page->id,
            'embedding' => $embedding,
            'article_text_sha256' => $page->article_text_sha256,
            'embedded_at' => now(),
        ]);
    }

    private function embeddingResponse(): PromiseInterface
    {
        return Http::response([
            'data' => [
                ['embedding' => array_fill(0, OpenAiEmbeddings::DIMENSIONS, 0.5), 'index' => 0],
            ],
        ]);
    }

    private function html(string $marker): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>題</title></head><body><article><p>'
            .$marker
            .'</p></article></body></html>';
    }

    private function embeddingCalls(): int
    {
        /** @var Collection<int, array{0: Request, 1: mixed}> $recorded */
        $recorded = collect(Http::recorded());

        return $recorded
            ->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/embeddings')
            ->count();
    }
}
