<?php

namespace Tests\Feature;

use App\Enums\ExtractionStatus;
use App\Jobs\CrawlSitemapPage;
use App\Models\Sitemap;
use App\Models\SitemapPage;
use App\Models\User;
use App\Services\ExtractSitemapPage;
use App\Services\ReadabilityTextExtractor;
use App\Services\TrafilaturaTextExtractor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SitemapExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_plain_text_response_is_marked_as_extraction_failed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/note' => Http::response('alpha', 200),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/note',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk()
            ->assertJsonMissingPath('extraction_label');

        $page->refresh();
        $this->assertSame('alpha', $page->body);
        $this->assertSame(ExtractionStatus::Failed, $page->extraction_status);
        $this->assertNull($page->articleText());
        $this->assertNull($page->article_text_sha256);
        $this->assertNotNull($page->extracted_at);
    }

    public function test_an_html_page_keeps_the_utf8_body_and_the_adopted_text(): void
    {
        if (! is_file(TrafilaturaTextExtractor::PYTHON)) {
            $this->fail('Trafilatura の Python がインストールされていません。');
        }

        $paragraph = '観葉植物は梅雨の間、土の表面が乾いてから水をやり、受け皿に残った水は朝に捨てる。';
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>梅雨の手入れ</title></head><body>'
            .'<header><nav>巡回対象外のナビ</nav></header><article><h1>梅雨の手入れ</h1>'
            .str_repeat('<p>'.$paragraph.'</p>', 12)
            .'</article><footer>フッタの定型文</footer></body></html>';

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/plant' => Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/plant',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertSame($html, $page->body);
        $this->assertNull($page->extraction_status);
        $this->assertStringContainsString('受け皿に残った水', (string) $page->articleText());
        $this->assertStringNotContainsString('巡回対象外のナビ', (string) $page->articleText());
        $this->assertSame(hash('sha256', (string) $page->articleText()), $page->article_text_sha256);
    }

    public function test_shift_jis_html_is_saved_as_utf8_before_extraction(): void
    {
        $paragraph = '観葉植物は土の表面が乾いてから水をやり、受け皿の水は捨てます。';
        $html = '<!DOCTYPE html><html><head><meta charset="Shift_JIS"><title>植物の手入れ</title></head><body><article>'
            .str_repeat('<p>'.$paragraph.'</p>', 12)
            .'</article></body></html>';
        $bytes = mb_convert_encoding($html, 'SJIS-win', 'UTF-8');

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/shift-jis' => Http::response($bytes, 200, [
                'Content-Type' => 'text/html; charset=Shift_JIS',
            ]),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/shift-jis',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertTrue(mb_check_encoding((string) $page->body, 'UTF-8'));
        $this->assertStringContainsString('植物の手入れ', (string) $page->body);
        $this->assertSame(hash('sha256', (string) $page->body), $page->body_sha256);
        $this->assertStringContainsString('受け皿の水', (string) $page->articleText());
    }

    public function test_a_body_that_cannot_be_converted_to_utf8_is_not_saved(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/broken' => Http::response("\xFF\xFE<html><body>壊れた本文</body></html>", 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/broken',
            'body' => '以前の本文',
            'body_sha256' => hash('sha256', '以前の本文'),
            'article_text' => '以前の抽出本文',
            'extracted_at' => '2026-10-14 00:00:00',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertSame(200, $page->http_status);
        $this->assertNull($page->body);
        $this->assertNull($page->body_sha256);
        $this->assertSame('文字コードを UTF-8 に変換できません。', $page->error_message);
        $this->assertSame(ExtractionStatus::Failed, $page->extraction_status);
        $this->assertNull($page->articleText());
        $this->assertNull($page->article_text_sha256);
        $this->assertNotSame('2026-10-14 00:00:00', $page->extracted_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_body_with_conflicting_charsets_can_be_extracted_later(): void
    {
        $paragraph = '観葉植物は土の表面が乾いてから水をやり、受け皿の水は捨てます。';
        $html = '<!DOCTYPE html><html><head><meta charset="Shift_JIS"><title>植物の手入れ</title></head><body><article>'
            .str_repeat('<p>'.$paragraph.'</p>', 12)
            .'</article></body></html>';

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/conflict' => Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/conflict',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertStringContainsString('<meta charset="UTF-8">', (string) $page->body);
        $this->assertSame(hash('sha256', (string) $page->body), $page->body_sha256);

        $page->update(['article_text' => null, 'extracted_at' => null]);

        $this->artisan('sitemaps:extract')
            ->expectsOutput('1 ページを抽出しました。')
            ->assertOk();

        $page->refresh();
        $this->assertNull($page->extraction_status);
        $this->assertStringContainsString('受け皿の水', (string) $page->articleText());
    }

    public function test_an_unchanged_body_keeps_the_previous_extraction(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/note' => Http::response('alpha', 200),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/note',
            'body' => 'alpha',
            'body_sha256' => hash('sha256', 'alpha'),
            'article_text' => '済',
            'extracted_at' => '2026-10-14 00:00:00',
            'crawled_at' => '2026-10-01 00:00:00',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertSame('済', $page->articleText());
        $this->assertSame(hash('sha256', '済'), $page->article_text_sha256);
        $this->assertNull($page->extraction_status);
        $this->assertSame('2026-10-14 00:00:00', $page->extracted_at?->format('Y-m-d H:i:s'));
        $this->assertNotSame('2026-10-01 00:00:00', $page->crawled_at?->format('Y-m-d H:i:s'));
    }

    public function test_an_unchanged_body_is_extracted_when_it_has_not_been_extracted(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/note' => Http::response('alpha', 200),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/note',
            'body' => 'alpha',
            'body_sha256' => hash('sha256', 'alpha'),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertSame(ExtractionStatus::Failed, $page->extraction_status);
        $this->assertNotNull($page->extracted_at);
    }

    public function test_a_failed_extraction_is_retried_for_the_same_body(): void
    {
        if (! is_file(TrafilaturaTextExtractor::PYTHON)) {
            $this->fail('Trafilatura の Python がインストールされていません。');
        }

        $paragraph = '観葉植物は梅雨の間、土の表面が乾いてから水をやり、受け皿に残った水は朝に捨てる。';
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>梅雨の手入れ</title></head><body><article>'
            .str_repeat('<p>'.$paragraph.'</p>', 12)
            .'</article></body></html>';

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/plant' => Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/plant',
            'body' => $html,
            'body_sha256' => hash('sha256', $html),
            'extraction_status' => ExtractionStatus::Failed,
            'extracted_at' => '2026-10-14 00:00:00',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertNull($page->extraction_status);
        $this->assertStringContainsString('受け皿に残った水', (string) $page->articleText());
        $this->assertNotSame('2026-10-14 00:00:00', $page->extracted_at?->format('Y-m-d H:i:s'));
    }

    public function test_one_failed_extractor_adopts_the_other_text(): void
    {
        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/one',
            'body' => '<!DOCTYPE html><html><head><title>t</title></head><body><p>本文</p></body></html>',
        ]);
        $failingTrafilatura = new class extends TrafilaturaTextExtractor
        {
            public function extract(string $html): ?string
            {
                return null;
            }
        };

        (new ExtractSitemapPage(trafilatura: $failingTrafilatura))->apply($page, 'text/html; charset=UTF-8');

        $page->refresh();
        $this->assertSame('本文', $page->articleText());
        $this->assertNull($page->extraction_status);
        $this->assertSame(hash('sha256', '本文'), $page->article_text_sha256);
    }

    public function test_both_failed_extractors_mark_the_page_failed(): void
    {
        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/none',
            'body' => '<!DOCTYPE html><html><head><title>t</title></head><body><p>本文</p></body></html>',
        ]);
        $failingReadability = new class extends ReadabilityTextExtractor
        {
            public function extract(string $html): ?string
            {
                return null;
            }
        };
        $failingTrafilatura = new class extends TrafilaturaTextExtractor
        {
            public function extract(string $html): ?string
            {
                return null;
            }
        };

        (new ExtractSitemapPage(readability: $failingReadability, trafilatura: $failingTrafilatura))
            ->apply($page, 'text/html; charset=UTF-8');

        $page->refresh();
        $this->assertNull($page->articleText());
        $this->assertSame(ExtractionStatus::Failed, $page->extraction_status);
        $this->assertNotNull($page->extracted_at);
    }

    public function test_an_empty_article_is_marked_empty_without_a_hash(): void
    {
        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/empty',
            'body' => '<!DOCTYPE html><html><head><title>t</title></head><body></body></html>',
        ]);
        $emptyReadability = new class extends ReadabilityTextExtractor
        {
            public function extract(string $html): ?string
            {
                return '';
            }
        };
        $emptyTrafilatura = new class extends TrafilaturaTextExtractor
        {
            public function extract(string $html): ?string
            {
                return '';
            }
        };

        (new ExtractSitemapPage(readability: $emptyReadability, trafilatura: $emptyTrafilatura))
            ->apply($page, 'text/html; charset=UTF-8');

        $page->refresh();
        $this->assertSame('', $page->articleText());
        $this->assertSame(ExtractionStatus::Empty, $page->extraction_status);
        $this->assertNull($page->article_text_sha256);
    }

    public function test_a_changed_body_is_extracted_again(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/note' => Http::response('alpha', 200),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/note',
            'body' => 'old',
            'body_sha256' => hash('sha256', 'old'),
            'article_text' => '済',
            'extracted_at' => '2026-10-14 00:00:00',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertSame('alpha', $page->body);
        $this->assertNull($page->articleText());
        $this->assertNull($page->article_text_sha256);
        $this->assertSame(ExtractionStatus::Failed, $page->extraction_status);
    }

    public function test_a_changed_body_does_not_keep_old_extraction_when_extraction_throws(): void
    {
        $html = '<!DOCTYPE html><html><head><title>新しい本文</title></head><body><article><p>新しい本文です。</p></article></body></html>';

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/changed' => Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/changed',
            'body' => '以前の本文',
            'body_sha256' => hash('sha256', '以前の本文'),
            'article_text' => '以前の抽出本文',
            'extracted_at' => '2026-10-14 00:00:00',
        ]);
        $this->app->instance(ExtractSitemapPage::class, new class extends ExtractSitemapPage
        {
            public function apply(SitemapPage $page, ?string $contentType): void
            {
                throw new RuntimeException('抽出処理の予期しないエラー');
            }
        });

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertSame($html, $page->body);
        $this->assertNull($page->articleText());
        $this->assertNull($page->article_text_sha256);
        $this->assertSame(ExtractionStatus::Failed, $page->extraction_status);
        $this->assertNotSame('2026-10-14 00:00:00', $page->extracted_at?->format('Y-m-d H:i:s'));
    }

    public function test_an_oversized_response_clears_the_previous_article_hash(): void
    {
        $payload = str_repeat('a', CrawlSitemapPage::MAX_BODY_BYTES + 1);

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/big' => Http::response($payload, 200, [
                'Content-Length' => (string) strlen($payload),
            ]),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/big',
            'body' => '以前の本文',
            'body_sha256' => hash('sha256', '以前の本文'),
            'article_text' => '以前の抽出本文',
            'extracted_at' => '2026-10-14 00:00:00',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk();

        $page->refresh();
        $this->assertNull($page->body);
        $this->assertNull($page->articleText());
        $this->assertNull($page->article_text_sha256);
        $this->assertNull($page->extracted_at);
    }

    public function test_a_stored_body_can_be_extracted_later(): void
    {
        if (! is_file(TrafilaturaTextExtractor::PYTHON)) {
            $this->fail('Trafilatura の Python がインストールされていません。');
        }

        $paragraph = '観葉植物は梅雨の間、土の表面が乾いてから水をやり、受け皿に残った水は朝に捨てる。';
        $html = '<!DOCTYPE html><html><head><title>梅雨の手入れ</title></head><body><article>'
            .str_repeat('<p>'.$paragraph.'</p>', 12)
            .'</article></body></html>';
        $sitemap = Sitemap::factory()->create();
        $pending = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/stored',
            'body' => $html,
            'body_sha256' => hash('sha256', $html),
        ]);
        $done = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/done',
            'body' => $html,
            'body_sha256' => hash('sha256', $html),
            'extracted_at' => '2026-10-14 00:00:00',
            'article_text' => '済',
        ]);
        $failed = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/failed',
            'body' => $html,
            'body_sha256' => hash('sha256', $html),
            'extraction_status' => ExtractionStatus::Failed,
            'extracted_at' => '2026-10-14 00:00:00',
        ]);

        $this->artisan('sitemaps:extract')
            ->expectsOutput('2 ページを抽出しました。')
            ->assertOk();

        $pending->refresh();
        $done->refresh();
        $failed->refresh();
        $this->assertNull($failed->extraction_status);
        $this->assertStringContainsString('受け皿に残った水', (string) $failed->articleText());
        $this->assertNotNull($pending->extracted_at);
        $this->assertNull($pending->extraction_status);
        $this->assertStringContainsString('受け皿に残った水', (string) $pending->articleText());
        $this->assertSame(hash('sha256', (string) $pending->articleText()), $pending->article_text_sha256);
        $this->assertSame('済', $done->articleText());
        $this->assertSame(hash('sha256', '済'), $done->article_text_sha256);
    }

    public function test_the_url_list_does_not_show_extraction_status(): void
    {
        $sitemap = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/split',
            'article_text' => 'Trafilaturaの本文',
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/waiting',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('https://example.com/split')
            ->assertSee('https://example.com/waiting')
            ->assertDontSee('抽出')
            ->assertDontSee('不一致')
            ->assertDontSee('採用は Trafilatura');
    }

    public function test_extract_command_is_not_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertFalse($commands->contains(fn (string $command): bool => str_contains($command, 'sitemaps:extract')));
    }
}
