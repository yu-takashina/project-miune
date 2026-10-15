<?php

namespace Tests\Feature;

use App\Enums\SitemapStatus;
use App\Jobs\CrawlSitemapPage;
use App\Models\Sitemap;
use App\Models\SitemapPage;
use App\Models\SitemapPageEmbedding;
use App\Services\CrawlDueSitemapPages;
use App\Services\ExtractSitemapPage;
use App\Services\OpenAiEmbeddings;
use App\Services\ReadabilityTextExtractor;
use App\Services\TrafilaturaTextExtractor;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Schedule;
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

    public function test_a_changed_article_is_embedded_during_the_crawl(): void
    {
        $this->fakeCrawl('本文A', '本文A');
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);

        $page->refresh();
        $this->assertSame('本文A', $page->articleText());
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

    public function test_the_same_body_stops_before_embedding(): void
    {
        $this->fakeCrawl('本文A', '本文A');
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);
        CrawlSitemapPage::dispatchSync($page->id);

        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(1, $page->embeddings()->count());
    }

    public function test_the_same_article_hash_stops_before_embedding(): void
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
        $this->insertEmbedding($page->refresh(), '[0]');
        CrawlSitemapPage::dispatchSync($page->id);

        $page->refresh();
        $this->assertSame('同じ本文', $page->articleText());
        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(2, $page->embeddings()->count());
        $this->assertTrue($page->embeddings()->where('embedding', '[0]')->exists());
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

    public function test_the_same_body_without_a_vector_embeds_without_extracting_again(): void
    {
        Http::fake([
            'https://example.com/note' => $this->htmlResponse(),
            'https://api.openai.com/v1/embeddings' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push([
                    'data' => [
                        ['embedding' => array_fill(0, OpenAiEmbeddings::DIMENSIONS, 0.5), 'index' => 0],
                    ],
                ]),
        ]);
        $readability = $this->createMock(ReadabilityTextExtractor::class);
        $readability->method('extract')->willReturnOnConsecutiveCalls('本文A', '別の本文');
        $trafilatura = $this->createMock(TrafilaturaTextExtractor::class);
        $trafilatura->method('extract')->willReturnOnConsecutiveCalls('本文A', '別の本文');
        $this->app->instance(ExtractSitemapPage::class, new ExtractSitemapPage(
            readability: $readability,
            trafilatura: $trafilatura,
        ));
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);
        CrawlSitemapPage::dispatchSync($page->id);

        $page->refresh();
        $this->assertSame('本文A', $page->articleText());
        $this->assertNull($page->embedding_error);
        $this->assertNull($page->embedding_failed_sha256);
        $this->assertSame(1, $page->embeddings()->count());
        $this->assertSame(2, $this->embeddingCalls());
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
        CrawlSitemapPage::dispatchSync($page->id);

        $page->refresh();
        $this->assertSame('長すぎる本文', $page->articleText());
        $this->assertSame('本文が長すぎてベクトル化できません。', $page->embedding_error);
        $this->assertSame(hash('sha256', '長すぎる本文'), $page->embedding_failed_sha256);
        $this->assertSame(0, $page->embeddings()->count());
        $this->assertSame(1, $this->embeddingCalls());
    }

    public function test_a_crawled_page_without_a_vector_stays_due_until_it_is_embedded(): void
    {
        $body = $this->html('one');
        Http::fake([
            'https://example.com/note' => $this->htmlResponse(),
            'https://api.openai.com/v1/embeddings' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push([
                    'data' => [
                        ['embedding' => array_fill(0, OpenAiEmbeddings::DIMENSIONS, 0.5), 'index' => 0],
                    ],
                ]),
        ]);
        $this->bindExtractor('本文A', '本文A');
        $sitemap = Sitemap::factory()->create(['status' => SitemapStatus::Crawling]);
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/note',
            'body' => $body,
            'body_sha256' => hash('sha256', $body),
            'article_text' => '本文A',
            'extracted_at' => now(),
            'crawled_at' => now(),
        ]);

        (new CrawlDueSitemapPages(0, 3600))->crawlListedPages();

        $this->assertSame(SitemapStatus::Crawling, $sitemap->refresh()->status);
        $this->assertSame(1, $this->embeddingCalls());
        $this->assertSame(0, $page->embeddings()->count());

        (new CrawlDueSitemapPages(0, 3600))->crawlListedPages();

        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
        $this->assertSame(1, $page->embeddings()->count());
        $this->assertSame(2, $this->embeddingCalls());
    }

    public function test_only_the_first_four_thousand_characters_are_sent(): void
    {
        $text = str_repeat('あ', OpenAiEmbeddings::MAX_INPUT_CHARACTERS + 1);
        $this->fakeCrawl($text, $text);
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.openai.com/v1/embeddings') {
                return false;
            }

            $input = $request->data()['input'] ?? null;

            return is_string($input) && mb_strlen($input) === OpenAiEmbeddings::MAX_INPUT_CHARACTERS;
        });
    }

    public function test_a_missing_api_key_leaves_the_extracted_article(): void
    {
        config(['services.openai.key' => null]);
        $this->fakeCrawl('本文A', '本文A');
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);

        $page->refresh();
        $this->assertSame('本文A', $page->articleText());
        $this->assertNull($page->embedding_error);
        $this->assertSame(0, $this->embeddingCalls());
    }

    public function test_an_authentication_failure_leaves_the_extracted_article(): void
    {
        Http::fake([
            'https://example.com/note' => Http::response($this->html('one'), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
            'https://api.openai.com/v1/embeddings' => Http::response([
                'error' => ['message' => 'invalid api key'],
            ], 401),
        ]);
        $this->bindExtractor('本文A', '本文A');
        $page = $this->page();

        CrawlSitemapPage::dispatchSync($page->id);

        $page->refresh();
        $this->assertSame('本文A', $page->articleText());
        $this->assertNull($page->embedding_error);
        $this->assertNull($page->embedding_failed_sha256);
        $this->assertSame(0, $page->embeddings()->count());
    }

    public function test_embed_is_not_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertFalse($commands->contains(
            fn (string $command): bool => str_contains($command, 'sitemaps:embed'),
        ));
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
