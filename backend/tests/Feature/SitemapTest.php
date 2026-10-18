<?php

namespace Tests\Feature;

use App\Enums\CrawlErrorKind;
use App\Enums\SitemapStatus;
use App\Jobs\CrawlSitemapPage;
use App\Jobs\InspectSitemap;
use App\Models\CrawlErrorLog;
use App\Models\Sitemap;
use App\Models\SitemapPage;
use App\Models\User;
use App\Services\CrawlDueSitemapPages;
use App\Services\SitemapUrlsetReader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_cannot_register_a_sitemap(): void
    {
        $this->post('/manager/sitemaps', [
            'url' => 'https://example.com/sitemap.xml',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('sitemaps', 0);
    }

    public function test_guests_cannot_delete_a_sitemap(): void
    {
        $sitemap = Sitemap::factory()->create();

        $this->delete('/manager/sitemaps/'.$sitemap->id)->assertRedirect('/login');

        $this->assertDatabaseHas('sitemaps', ['id' => $sitemap->id]);
    }

    public function test_the_same_url_cannot_be_registered_twice(): void
    {
        $user = User::factory()->create();
        $url = 'https://example.com/sitemap.xml';

        Sitemap::factory()->create(['url' => $url]);

        $this->actingAs($user)
            ->from('/manager/sitemap')
            ->post('/manager/sitemaps', ['url' => $url])
            ->assertRedirect('/manager/sitemap')
            ->assertSessionHasErrors('url');

        $this->assertDatabaseCount('sitemaps', 1);
    }

    public function test_url_differences_in_case_or_trailing_slash_are_not_duplicates(): void
    {
        Http::fake(fn () => Http::response($this->urlset('https://example.com/a'), 200));

        $user = User::factory()->create();

        $this->actingAs($user)->post('/manager/sitemaps', [
            'url' => 'https://example.com/sitemap.xml',
        ])->assertRedirect('/manager/sitemap');

        $this->actingAs($user)->post('/manager/sitemaps', [
            'url' => 'https://example.com/Sitemap.xml',
        ])->assertRedirect('/manager/sitemap');

        $this->actingAs($user)->post('/manager/sitemaps', [
            'url' => 'https://example.com/sitemap.xml/',
        ])->assertRedirect('/manager/sitemap');

        $this->assertDatabaseCount('sitemaps', 3);
    }

    public function test_a_valid_urlset_is_crawled_and_hashed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(
                'https://example.com/a',
                'https://example.com/b',
            ), 200),
            'https://example.com/a' => Http::response('alpha', 200, [
                'Content-Length' => '5',
            ]),
            'https://example.com/b' => Http::response('bravo', 201),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml'])
            ->assertRedirect('/manager/sitemap');

        $sitemap = Sitemap::query()->firstOrFail();
        $this->assertSame(SitemapStatus::Pending, $sitemap->status);
        $this->assertSame(0, $sitemap->pages()->count());

        $this->processDueSitemaps();

        $sitemap->refresh();
        $this->assertSame(SitemapStatus::Completed, $sitemap->status);
        $this->assertNull($sitemap->error_message);
        $this->assertNotNull($sitemap->crawled_at);
        $this->assertSame(2, $sitemap->pages()->count());

        $alpha = SitemapPage::query()->where('url', 'https://example.com/a')->firstOrFail();
        $this->assertTrue($alpha->searchable);
        $this->assertNull($alpha->unlisted_at);
        $this->assertSame(0, $alpha->absence_count);
        $this->assertSame(200, $alpha->http_status);
        $this->assertSame(5, $alpha->response_bytes);
        $this->assertSame('alpha', $alpha->body);
        $this->assertSame(hash('sha256', 'alpha'), $alpha->body_sha256);
        $this->assertNotNull($alpha->crawled_at);

        $bravo = SitemapPage::query()->where('url', 'https://example.com/b')->firstOrFail();
        $this->assertSame(201, $bravo->http_status);
        $this->assertSame(5, $bravo->response_bytes);
        $this->assertSame(hash('sha256', 'bravo'), $bravo->body_sha256);

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('https://example.com/sitemap.xml')
            ->assertSee('href="https://example.com/sitemap.xml"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('aria-label="別ウィンドウで開く"', false)
            ->assertSee('巡回済')
            ->assertSeeInOrder(['削除までの回数', '巡回日時'])
            ->assertSee('data-sitemap-crawled-at', false)
            ->assertSee($sitemap->crawled_at->format('Y-m-d H:i'));
    }

    public function test_broken_xml_is_marked_invalid(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('<html></html>', 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml']);

        $this->processDueSitemaps();

        $sitemap = Sitemap::query()->firstOrFail();

        $this->assertSame(SitemapStatus::Invalid, $sitemap->status);
        $this->assertSame('sitemap.xml の書式が正しくありません。', $sitemap->error_message);
        $this->assertNull($sitemap->crawled_at);
        $this->assertSame(0, $sitemap->pages()->count());

        $this->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('状態')
            ->assertDontSee('書式エラー')
            ->assertDontSee('>エラー<', false)
            ->assertSee('class="px-3 py-2 text-[#f53003]">sitemap.xml の書式が正しくありません。', false);
    }

    public function test_a_sitemap_index_collects_nested_pages_under_the_parent_only(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapIndex(
                'https://example.com/posts.xml',
                'https://example.com/pages.xml',
            ), 200),
            'https://example.com/posts.xml' => Http::response($this->sitemapIndex(
                'https://example.com/posts-1.xml',
            ), 200),
            'https://example.com/posts-1.xml' => Http::response($this->urlset(
                'https://example.com/p1',
                'https://example.com/shared',
            ), 200),
            'https://example.com/pages.xml' => Http::response($this->urlset(
                'https://example.com/shared',
                'https://example.com/about',
            ), 200),
            'https://example.com/p1' => Http::response('p1', 200),
            'https://example.com/shared' => Http::response('shared', 200),
            'https://example.com/about' => Http::response('about', 200),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml'])
            ->assertRedirect('/manager/sitemap');

        $this->processDueSitemaps();

        $this->assertDatabaseCount('sitemaps', 1);
        $this->assertDatabaseMissing('sitemaps', ['url' => 'https://example.com/posts.xml']);
        $this->assertDatabaseMissing('sitemaps', ['url' => 'https://example.com/posts-1.xml']);
        $this->assertDatabaseMissing('sitemaps', ['url' => 'https://example.com/pages.xml']);

        $sitemap = Sitemap::query()->firstOrFail();
        $this->assertSame(SitemapStatus::Completed, $sitemap->status);
        $this->assertSame(
            ['https://example.com/p1', 'https://example.com/shared', 'https://example.com/about'],
            $sitemap->pages()->orderBy('id')->pluck('url')->all(),
        );

        $this->actingAs($user)
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('https://example.com/sitemap.xml')
            ->assertDontSee('https://example.com/posts.xml')
            ->assertDontSee('https://example.com/posts-1.xml')
            ->assertDontSee('https://example.com/pages.xml');

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('https://example.com/sitemap.xml')
            ->assertSee('https://example.com/p1')
            ->assertSee('https://example.com/shared')
            ->assertSee('https://example.com/about')
            ->assertDontSee('https://example.com/posts.xml')
            ->assertDontSee('https://example.com/posts-1.xml')
            ->assertDontSee('https://example.com/pages.xml');
    }

    public function test_the_same_page_url_can_be_stored_for_another_sitemap(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/a.xml' => Http::response($this->sitemapIndex('https://example.com/child.xml'), 200),
            'https://example.com/b.xml' => Http::response($this->urlset('https://example.com/page'), 200),
            'https://example.com/child.xml' => Http::response($this->urlset('https://example.com/page'), 200),
            'https://example.com/page' => Http::response('page', 200),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/manager/sitemaps', ['url' => 'https://example.com/a.xml']);
        $this->actingAs($user)->post('/manager/sitemaps', ['url' => 'https://example.com/b.xml']);

        $this->processDueSitemaps();

        $this->assertDatabaseCount('sitemaps', 2);
        $this->assertDatabaseMissing('sitemaps', ['url' => 'https://example.com/child.xml']);
        $this->assertSame(2, SitemapPage::query()->where('url', 'https://example.com/page')->count());

        $sitemapIds = SitemapPage::query()->where('url', 'https://example.com/page')->pluck('sitemap_id')->all();
        $this->assertCount(2, array_unique($sitemapIds));
    }

    public function test_a_repeated_sitemap_url_is_not_fetched_again(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapIndex(
                'https://example.com/child.xml',
            ), 200),
            'https://example.com/child.xml' => Http::response($this->sitemapIndex(
                'https://example.com/sitemap.xml',
                'https://example.com/pages.xml',
            ), 200),
            'https://example.com/pages.xml' => Http::response($this->urlset('https://example.com/page'), 200),
            'https://example.com/page' => Http::response('page', 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml']);

        $recorded = Http::recorded()->count();

        $this->processDueSitemaps();

        $sitemap = Sitemap::query()->firstOrFail();
        $this->assertSame(SitemapStatus::Completed, $sitemap->status);
        $this->assertSame(['https://example.com/page'], $sitemap->pages()->pluck('url')->all());

        $fetches = Http::recorded()->slice($recorded)->filter(
            fn (array $pair): bool => $pair[0]->url() === 'https://example.com/sitemap.xml',
        )->count();

        $this->assertSame(1, $fetches);
    }

    public function test_a_sitemap_deeper_than_the_limit_is_rejected_on_registration(): void
    {
        Http::preventStrayRequests();
        ['responses' => $responses, 'beyond' => $beyond] = $this->sitemapDeeperThanTheLimit();
        Http::fake($responses);

        $response = $this->actingAs(User::factory()->create())
            ->from('/manager/sitemap')
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml']);

        $response->assertRedirect('/manager/sitemap');

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('sitemap の深さが 5 を超えています。')
            ->assertSee('value="https://example.com/sitemap.xml"', false);

        $this->assertDatabaseCount('sitemaps', 0);
        $this->assertDatabaseCount('sitemap_pages', 0);
        Http::assertNotSent(fn ($request): bool => $request->url() === $beyond);
    }

    public function test_a_sitemap_at_the_depth_limit_is_registered(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->sitemapAtTheDepthLimit());

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml'])
            ->assertRedirect('/manager/sitemap')
            ->assertSessionHasNoErrors();

        $this->processDueSitemaps();

        $sitemap = Sitemap::query()->firstOrFail();
        $this->assertSame(SitemapStatus::Completed, $sitemap->status);
        $this->assertNull($sitemap->error_message);
        $this->assertSame(['https://example.com/within'], $sitemap->pages()->pluck('url')->all());
    }

    public function test_a_pending_sitemap_deeper_than_the_limit_stores_the_error_and_skips_pages(): void
    {
        Http::preventStrayRequests();
        ['responses' => $responses, 'beyond' => $beyond] = $this->sitemapDeeperThanTheLimit();
        Http::fake($responses);

        $sitemap = Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Pending,
        ]);

        $this->processDueSitemaps();

        $sitemap->refresh();
        $this->assertSame(SitemapStatus::Invalid, $sitemap->status);
        $this->assertSame(InspectSitemap::depthErrorMessage(), $sitemap->error_message);
        $this->assertSame(0, $sitemap->pages()->count());
        Http::assertNotSent(fn ($request): bool => $request->url() === $beyond);
    }

    public function test_a_refresh_records_a_depth_error_without_changing_pages(): void
    {
        Http::preventStrayRequests();
        ['responses' => $responses, 'beyond' => $beyond] = $this->sitemapDeeperThanTheLimit();
        Http::fake($responses);

        $crawledAt = now()->subDay()->startOfSecond();
        $sitemap = $this->completedSitemap();
        $sitemap->crawled_at = $crawledAt;
        $sitemap->save();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kept',
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
        ]);

        $this->refreshSitemap($sitemap);

        $page->refresh();
        $sitemap->refresh();
        $this->assertSame(SitemapStatus::Completed, $sitemap->status);
        $this->assertSame(InspectSitemap::depthErrorMessage(), $sitemap->error_message);
        $this->assertTrue($crawledAt->equalTo($sitemap->crawled_at));
        $this->assertNull($page->unlisted_at);
        $this->assertSame(0, $page->absence_count);
        $this->assertSame('kept', $page->body);
        $this->assertDatabaseMissing('sitemap_pages', ['url' => 'https://example.com/within']);
        Http::assertNotSent(fn ($request): bool => $request->url() === $beyond);
    }

    public function test_a_refresh_clears_a_depth_error_when_the_sitemap_is_within_the_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/kept'), 200),
        ]);

        $sitemap = $this->completedSitemap();
        $sitemap->error_message = InspectSitemap::depthErrorMessage();
        $sitemap->save();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kept',
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
        ]);

        $this->refreshSitemap($sitemap);

        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
        $this->assertNull($sitemap->error_message);
        $this->assertSame('kept', $page->refresh()->body);
        $this->assertNull($page->unlisted_at);
    }

    public function test_an_immediate_crawl_records_a_depth_error_and_does_not_refetch_pages(): void
    {
        Http::preventStrayRequests();
        ['responses' => $responses] = $this->sitemapDeeperThanTheLimit();
        Http::fake($responses);

        $sitemap = $this->completedSitemap();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kept',
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
            'crawled_at' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/crawl')
            ->assertOk()
            ->assertJson([
                'ran' => true,
                'status_label' => '巡回済',
                'error_message' => InspectSitemap::depthErrorMessage(),
                'pages_count' => 1,
            ]);

        $sitemap->refresh();
        $page->refresh();
        $this->assertSame(SitemapStatus::Completed, $sitemap->status);
        $this->assertSame(InspectSitemap::depthErrorMessage(), $sitemap->error_message);
        $this->assertSame('kept', $page->body);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.com/kept');
    }

    public function test_a_repeated_sitemap_url_past_the_depth_limit_is_not_a_depth_error(): void
    {
        Http::preventStrayRequests();

        $responses = [
            'https://example.com/pages.xml' => Http::response($this->urlset('https://example.com/page'), 200),
            'https://example.com/page' => Http::response('page', 200),
        ];
        $child = 'https://example.com/sitemap.xml';

        for ($depth = InspectSitemap::MAX_DEPTH; $depth >= 2; $depth--) {
            $url = 'https://example.com/level-'.$depth.'.xml';
            $responses[$url] = Http::response($this->sitemapIndex($child), 200);
            $child = $url;
        }

        $responses['https://example.com/sitemap.xml'] = Http::response($this->sitemapIndex(
            'https://example.com/pages.xml',
            $child,
        ), 200);

        Http::fake($responses);

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml'])
            ->assertRedirect('/manager/sitemap')
            ->assertSessionHasNoErrors();

        $this->processDueSitemaps();

        $sitemap = Sitemap::query()->firstOrFail();
        $this->assertSame(SitemapStatus::Completed, $sitemap->status);
        $this->assertNull($sitemap->error_message);
        $this->assertSame(['https://example.com/page'], $sitemap->pages()->pluck('url')->all());
    }

    public function test_a_broken_child_sitemap_marks_the_parent_invalid(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapIndex(
                'https://example.com/child.xml',
            ), 200),
            'https://example.com/child.xml' => Http::response('<html></html>', 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml']);

        $this->processDueSitemaps();

        $sitemap = Sitemap::query()->firstOrFail();

        $this->assertSame(SitemapStatus::Invalid, $sitemap->status);
        $this->assertSame('sitemap.xml の書式が正しくありません。', $sitemap->error_message);
        $this->assertSame(0, $sitemap->pages()->count());
        $this->assertDatabaseCount('sitemaps', 1);
    }

    public function test_a_failed_child_sitemap_marks_the_parent_failed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapIndex(
                'https://example.com/child.xml',
            ), 200),
            'https://example.com/child.xml' => Http::response('missing', 404),
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml']);

        $this->processDueSitemaps();

        $sitemap = Sitemap::query()->firstOrFail();

        $this->assertSame(SitemapStatus::Failed, $sitemap->status);
        $this->assertSame('sitemap.xml の取得に失敗しました（HTTP 404）。', $sitemap->error_message);
        $this->assertSame(0, $sitemap->pages()->count());
    }

    public function test_http_errors_while_fetching_a_sitemap_are_marked_failed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('missing', 404),
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml']);

        $this->processDueSitemaps();

        $sitemap = Sitemap::query()->firstOrFail();

        $this->assertSame(SitemapStatus::Failed, $sitemap->status);
        $this->assertSame('sitemap.xml の取得に失敗しました（HTTP 404）。', $sitemap->error_message);
    }

    public function test_deleting_a_sitemap_deletes_crawled_pages(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/a'), 200),
            'https://example.com/a' => Http::response('alpha', 200),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/manager/sitemaps', [
            'url' => 'https://example.com/sitemap.xml',
        ]);

        $this->processDueSitemaps();

        $sitemap = Sitemap::query()->firstOrFail();
        $pageId = SitemapPage::query()->value('id');

        $this->actingAs($user)
            ->delete('/manager/sitemaps/'.$sitemap->id)
            ->assertRedirect('/manager/sitemap');

        $this->assertDatabaseMissing('sitemaps', ['id' => $sitemap->id]);
        $this->assertDatabaseMissing('sitemap_pages', ['id' => $pageId]);
    }

    public function test_a_missing_page_is_skipped_by_the_crawl_job(): void
    {
        $page = SitemapPage::query()->create([
            'sitemap_id' => Sitemap::factory()->create()->id,
            'url' => 'https://example.com/a',
        ]);

        $page->delete();

        (new CrawlSitemapPage($page->id))->handle();

        $this->assertDatabaseCount('sitemap_pages', 0);
    }

    public function test_an_oversized_response_keeps_the_status_and_size_without_the_body(): void
    {
        $payload = str_repeat('a', CrawlSitemapPage::MAX_BODY_BYTES + 1);

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/big'), 200),
            'https://example.com/big' => Http::response($payload, 200, [
                'Content-Length' => (string) strlen($payload),
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps', ['url' => 'https://example.com/sitemap.xml']);

        $this->processDueSitemaps();

        $page = SitemapPage::query()->firstOrFail();

        $this->assertSame(200, $page->http_status);
        $this->assertSame(strlen($payload), $page->response_bytes);
        $this->assertNull($page->body);
        $this->assertNull($page->body_sha256);
        $this->assertSame('レスポンスが大きすぎます。', $page->error_message);
        $this->assertSame(SitemapStatus::Completed, Sitemap::query()->firstOrFail()->status);
    }

    public function test_a_crawl_run_stops_when_the_time_budget_is_spent(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/a' => Http::response('alpha', 200),
            'https://example.com/b' => Http::response('bravo', 200),
        ]);

        $sitemap = $this->completedSitemap();
        $first = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
        ]);
        $second = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/b',
        ]);

        (new CrawlDueSitemapPages(0, 0))->run();

        $this->assertSame('alpha', $first->refresh()->body);
        $this->assertNull($second->refresh()->crawled_at);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.com/b');

        (new CrawlDueSitemapPages(0, 0))->run();

        $this->assertSame('bravo', $second->refresh()->body);
    }

    public function test_crawling_skips_pages_that_already_finished_or_left_the_sitemap(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/pending' => Http::response('pending', 200),
            'https://example.com/done' => Http::response('should-not-replace', 200),
            'https://example.com/gone' => Http::response('gone', 200),
        ]);

        $sitemap = $this->completedSitemap();
        $pending = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/pending',
        ]);
        $done = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/done',
            'body' => 'done',
            'crawled_at' => now()->subDays(CrawlDueSitemapPages::RECRAWL_DAYS)->addMinute(),
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/gone',
            'unlisted_at' => now(),
        ]);

        (new CrawlDueSitemapPages(0, 3600))->run();

        $this->assertSame('pending', $pending->refresh()->body);
        $this->assertSame('done', $done->refresh()->body);
        Http::assertNotSent(fn ($request): bool => in_array($request->url(), [
            'https://example.com/done',
            'https://example.com/gone',
        ], true));
    }

    public function test_a_page_crawled_seven_days_ago_is_fetched_again(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/old' => Http::response('new body', 200),
        ]);

        $page = SitemapPage::query()->create([
            'sitemap_id' => $this->completedSitemap()->id,
            'url' => 'https://example.com/old',
            'body' => 'old body',
            'body_sha256' => hash('sha256', 'old body'),
            'crawled_at' => now()->subDays(CrawlDueSitemapPages::RECRAWL_DAYS),
        ]);

        (new CrawlDueSitemapPages(0, 3600))->run();

        $page->refresh();
        $this->assertSame('new body', $page->body);
        $this->assertTrue($page->crawled_at?->isToday());
    }

    public function test_a_failed_fetch_doubles_the_wait_up_to_one_day(): void
    {
        $this->freezeSecond();
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/down' => fn () => throw new ConnectionException('down'),
        ]);

        $crawledAt = now()->subDays(30);
        $page = SitemapPage::query()->create([
            'sitemap_id' => $this->completedSitemap()->id,
            'url' => 'https://example.com/down',
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
            'crawled_at' => $crawledAt,
        ]);

        (new CrawlDueSitemapPages(0, 3600))->run();

        $page->refresh();
        $this->assertSame(1, $page->fetch_failure_count);
        $this->assertTrue(now()->addMinute()->equalTo($page->fetch_retry_at));
        $this->assertTrue($crawledAt->equalTo($page->crawled_at));
        $this->assertSame('kept', $page->body);
        $this->assertSame('取得に失敗しました。', $page->error_message);
        $this->assertDatabaseHas('crawl_error_logs', [
            'kind' => CrawlErrorKind::Page->value,
            'sitemap_page_id' => $page->id,
            'url' => 'https://example.com/down',
        ]);

        (new CrawlDueSitemapPages(0, 3600))->run();
        $this->assertSame(1, $page->refresh()->fetch_failure_count);

        $this->travel(1)->minutes();
        (new CrawlDueSitemapPages(0, 3600))->run();

        $page->refresh();
        $this->assertSame(2, $page->fetch_failure_count);
        $this->assertTrue(now()->addMinutes(2)->equalTo($page->fetch_retry_at));

        $page->update(['fetch_failure_count' => 20]);
        $this->travel(2)->minutes();
        (new CrawlDueSitemapPages(0, 3600))->run();

        $page->refresh();
        $this->assertSame(21, $page->fetch_failure_count);
        $this->assertTrue(now()->addDay()->equalTo($page->fetch_retry_at));
        $this->assertSame(3, CrawlErrorLog::query()->count());
    }

    public function test_a_never_fetched_page_keeps_an_empty_crawled_at_after_a_failed_fetch(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/down' => fn () => throw new ConnectionException('down'),
        ]);

        $page = SitemapPage::query()->create([
            'sitemap_id' => $this->completedSitemap()->id,
            'url' => 'https://example.com/down',
        ]);

        (new CrawlDueSitemapPages(0, 3600))->run();

        $page->refresh();
        $this->assertNull($page->crawled_at);
        $this->assertSame(1, $page->fetch_failure_count);
    }

    public function test_a_successful_fetch_resets_the_failure_count(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/back' => Http::response('missing', 500),
        ]);

        $page = SitemapPage::query()->create([
            'sitemap_id' => $this->completedSitemap()->id,
            'url' => 'https://example.com/back',
            'fetch_failure_count' => 5,
            'fetch_retry_at' => now()->subSecond(),
        ]);

        (new CrawlDueSitemapPages(0, 3600))->run();

        $page->refresh();
        $this->assertSame(500, $page->http_status);
        $this->assertSame(0, $page->fetch_failure_count);
        $this->assertNull($page->fetch_retry_at);
        $this->assertNull($page->error_message);
    }

    public function test_an_http_404_is_treated_as_a_failed_fetch(): void
    {
        $this->freezeSecond();
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/missing' => Http::response('gone', 404),
            'https://example.com/new' => Http::response('gone', 404),
        ]);

        $crawledAt = now()->subDays(30);
        $page = SitemapPage::query()->create([
            'sitemap_id' => $this->completedSitemap()->id,
            'url' => 'https://example.com/missing',
            'http_status' => 200,
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
            'article_text' => 'kept',
            'crawled_at' => $crawledAt,
        ]);
        $fresh = SitemapPage::query()->create([
            'sitemap_id' => $page->sitemap_id,
            'url' => 'https://example.com/new',
        ]);

        (new CrawlDueSitemapPages(0, 3600))->run();

        $page->refresh();
        $this->assertSame(200, $page->http_status);
        $this->assertSame('kept', $page->body);
        $this->assertSame('kept', $page->article_text);
        $this->assertTrue($crawledAt->equalTo($page->crawled_at));
        $this->assertSame(1, $page->fetch_failure_count);
        $this->assertTrue(now()->addMinute()->equalTo($page->fetch_retry_at));
        $this->assertSame('取得に失敗しました。', $page->error_message);
        $this->assertDatabaseHas('crawl_error_logs', [
            'kind' => CrawlErrorKind::Page->value,
            'sitemap_page_id' => $page->id,
            'http_status' => 404,
            'message' => '取得に失敗しました。',
        ]);

        $fresh->refresh();
        $this->assertNull($fresh->crawled_at);
        $this->assertNull($fresh->http_status);
        $this->assertNull($fresh->body);
        $this->assertSame(1, $fresh->fetch_failure_count);
        $this->assertSame('取得に失敗しました。', $fresh->error_message);
    }

    public function test_guests_cannot_view_sitemap_urls(): void
    {
        $sitemap = Sitemap::factory()->create();

        $this->get('/manager/sitemaps/'.$sitemap->id)->assertRedirect('/login');
    }

    public function test_published_count_links_to_the_url_list_without_the_response_body(): void
    {
        $sitemap = Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Completed,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
            'http_status' => 200,
            'response_bytes' => 5,
            'body' => 'secret-body',
            'crawled_at' => '2026-10-13 17:00:00',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('/manager/sitemaps/'.$sitemap->id, false);

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('https://example.com/sitemap.xml')
            ->assertSee('href="https://example.com/sitemap.xml"', false)
            ->assertSee('https://example.com/a')
            ->assertSee('href="https://example.com/a"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('aria-label="別ウィンドウで開く"', false)
            ->assertSee('200')
            ->assertSee('2026-10-13 17:00')
            ->assertSee('検索対象')
            ->assertSee('aria-checked="true"', false)
            ->assertDontSee('data-search-state', false)
            ->assertDontSee('secret-body');
    }

    public function test_guests_cannot_change_search_targets(): void
    {
        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
        ]);

        $this->patchJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id, [
            'searchable' => false,
        ])->assertUnauthorized();

        $this->assertTrue($page->refresh()->searchable);
    }

    public function test_a_url_can_be_excluded_from_search_without_a_message(): void
    {
        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id, [
                'searchable' => false,
            ])
            ->assertOk()
            ->assertExactJson(['searchable' => false]);

        $this->assertFalse($page->refresh()->searchable);

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('aria-checked="false"', false)
            ->assertDontSee('data-search-state', false)
            ->assertSee('data-search-off="true"', false);
    }

    public function test_a_page_from_another_sitemap_cannot_be_toggled(): void
    {
        $sitemap = Sitemap::factory()->create();
        $other = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $other->id,
            'url' => 'https://example.com/a',
        ]);

        $this->actingAs(User::factory()->create())
            ->patchJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id, [
                'searchable' => false,
            ])
            ->assertNotFound();

        $this->assertTrue($page->refresh()->searchable);
    }

    public function test_the_url_list_can_be_filtered_from_the_search_box(): void
    {
        $sitemap = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/alpha',
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/beta',
        ]);
        $other = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $other->id,
            'url' => 'https://example.com/alpha-other',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id.'?q=alpha')
            ->assertOk()
            ->assertSee('https://example.com/alpha')
            ->assertDontSee('https://example.com/beta')
            ->assertDontSee('https://example.com/alpha-other');

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id.'?q=missing')
            ->assertOk()
            ->assertSee('一致する URL はありません。')
            ->assertDontSee('https://example.com/alpha');
    }

    public function test_the_url_list_can_be_filtered_by_search_target(): void
    {
        $sitemap = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/on',
            'searchable' => true,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/off',
            'searchable' => false,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id.'?searchable=1')
            ->assertOk()
            ->assertSee('https://example.com/on')
            ->assertDontSee('https://example.com/off');

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id.'?searchable=0&q=example.com')
            ->assertOk()
            ->assertSee('https://example.com/off')
            ->assertDontSee('https://example.com/on');

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id.'?searchable=0&q=missing')
            ->assertOk()
            ->assertSee('一致する URL はありません。');
    }

    public function test_the_url_list_can_be_filtered_by_http_status(): void
    {
        $sitemap = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/ok',
            'http_status' => 200,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/missing',
            'http_status' => 404,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/moved',
            'http_status' => 301,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/unknown',
        ]);

        $user = User::factory()->create();
        $base = '/manager/sitemaps/'.$sitemap->id;

        $this->actingAs($user)
            ->get($base.'?status=200')
            ->assertOk()
            ->assertSee('https://example.com/ok')
            ->assertDontSee('https://example.com/missing')
            ->assertDontSee('https://example.com/moved')
            ->assertDontSee('https://example.com/unknown')
            ->assertSee('value="200" selected', false);

        $this->actingAs($user)
            ->get($base.'?status=404')
            ->assertOk()
            ->assertSee('https://example.com/missing')
            ->assertDontSee('https://example.com/ok')
            ->assertDontSee('https://example.com/moved')
            ->assertDontSee('https://example.com/unknown');

        $this->actingAs($user)
            ->get($base.'?status=other')
            ->assertOk()
            ->assertSee('https://example.com/moved')
            ->assertDontSee('https://example.com/ok')
            ->assertDontSee('https://example.com/missing')
            ->assertDontSee('https://example.com/unknown')
            ->assertSee('value="other" selected', false);

        $this->actingAs($user)
            ->get($base)
            ->assertOk()
            ->assertSee('https://example.com/ok')
            ->assertSee('https://example.com/missing')
            ->assertSee('https://example.com/moved')
            ->assertSee('https://example.com/unknown')
            ->assertSee('>全て</option>', false);

        $this->actingAs($user)
            ->get($base.'?status=500')
            ->assertOk()
            ->assertSee('https://example.com/ok')
            ->assertSee('https://example.com/unknown');

        $this->actingAs($user)
            ->get($base.'?status=other&q=absent')
            ->assertOk()
            ->assertSee('一致する URL はありません。')
            ->assertDontSee('https://example.com/moved');

        $this->actingAs($user)
            ->get($base.'?status=200&sort=url&order=desc')
            ->assertOk()
            ->assertSee('status=200', false);
    }

    public function test_the_url_list_can_be_sorted_by_url_size_and_crawled_at(): void
    {
        $sitemap = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/unknown',
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/one-kilobyte',
            'response_bytes' => 1536,
            'crawled_at' => '2026-10-01 09:00:00',
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/half-kilobyte',
            'response_bytes' => 512,
            'crawled_at' => '2026-10-13 12:00:00',
        ]);

        $user = User::factory()->create();
        $base = '/manager/sitemaps/'.$sitemap->id;

        $this->actingAs($user)
            ->get($base)
            ->assertOk()
            ->assertSeeInOrder([
                'https://example.com/half-kilobyte',
                'https://example.com/one-kilobyte',
                'https://example.com/unknown',
            ])
            ->assertSee('aria-label="URLの昇順"', false)
            ->assertSee('aria-label="URLの降順"', false)
            ->assertSee('aria-label="容量の昇順"', false)
            ->assertSee('aria-label="容量の降順"', false)
            ->assertSee('aria-label="巡回日時の昇順"', false)
            ->assertSee('aria-label="巡回日時の降順"', false)
            ->assertSee('aria-current="true"', false)
            ->assertSee('name="sort" value="url"', false)
            ->assertSee('name="order" value="asc"', false)
            ->assertSee('sort=size&order=asc');

        $this->actingAs($user)
            ->get($base.'?sort=size&order=asc')
            ->assertOk()
            ->assertSeeInOrder([
                'https://example.com/half-kilobyte',
                'https://example.com/one-kilobyte',
                'https://example.com/unknown',
            ])
            ->assertSee('aria-current="true"', false);

        $this->actingAs($user)
            ->get($base.'?sort=size&order=desc')
            ->assertOk()
            ->assertSeeInOrder([
                'https://example.com/one-kilobyte',
                'https://example.com/half-kilobyte',
                'https://example.com/unknown',
            ]);

        $this->actingAs($user)
            ->get($base.'?sort=crawled_at&order=asc')
            ->assertOk()
            ->assertSeeInOrder([
                'https://example.com/one-kilobyte',
                'https://example.com/half-kilobyte',
                'https://example.com/unknown',
            ]);

        $this->actingAs($user)
            ->get($base.'?sort=crawled_at&order=desc')
            ->assertOk()
            ->assertSeeInOrder([
                'https://example.com/half-kilobyte',
                'https://example.com/one-kilobyte',
                'https://example.com/unknown',
            ]);

        $this->actingAs($user)
            ->get($base.'?q=half-kilobyte&searchable=1&sort=size&order=desc')
            ->assertOk()
            ->assertSee('https://example.com/half-kilobyte')
            ->assertDontSee('https://example.com/one-kilobyte')
            ->assertSee('name="sort" value="size"', false)
            ->assertSee('name="order" value="desc"', false)
            ->assertSee('q=half-kilobyte', false)
            ->assertSee('searchable=1', false);

        $this->actingAs($user)
            ->get($base.'?sort=url&order=desc')
            ->assertOk()
            ->assertSeeInOrder([
                'https://example.com/unknown',
                'https://example.com/one-kilobyte',
                'https://example.com/half-kilobyte',
            ]);

        $this->actingAs($user)
            ->get($base.'?sort=status&order=desc')
            ->assertOk()
            ->assertSeeInOrder([
                'https://example.com/half-kilobyte',
                'https://example.com/one-kilobyte',
                'https://example.com/unknown',
            ])
            ->assertSee('name="sort" value="url"', false);
    }

    public function test_response_size_is_shown_with_a_unit(): void
    {
        $sitemap = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/bytes',
            'response_bytes' => 512,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kilobytes',
            'response_bytes' => 1536,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/megabytes',
            'response_bytes' => 1024 * 1024,
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('512 B')
            ->assertSee('1.5 KB')
            ->assertSee('1 MB')
            ->assertDontSee('1536');
    }

    public function test_a_page_error_is_shown_in_the_status_column(): void
    {
        $sitemap = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/ok',
            'http_status' => 200,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/big',
            'http_status' => 200,
            'error_message' => 'レスポンスが大きすぎます。',
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/down',
            'error_message' => '取得に失敗しました。',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('ステータス')
            ->assertDontSee('エラー')
            ->assertDontSee('data-crawl-error', false)
            ->assertSee('data-crawl-status>200', false)
            ->assertSee('data-crawl-status>レスポンスが大きすぎます。', false)
            ->assertSee('data-crawl-status>取得に失敗しました。', false)
            ->assertSee('text-[#f53003]', false);
    }

    public function test_a_url_can_be_crawled_immediately(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/alpha' => Http::response('crawled', 200, [
                'Content-Length' => '7',
            ]),
        ]);

        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/alpha',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('今すぐ巡回')
            ->assertSee('data-crawl', false);

        $this->actingAs($user)
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertOk()
            ->assertJson([
                'http_status' => 200,
                'response_size' => '7 B',
                'error_message' => null,
            ]);

        $page->refresh();
        $this->assertSame(200, $page->http_status);
        $this->assertSame(7, $page->response_bytes);
        $this->assertSame('crawled', $page->body);
        $this->assertNotNull($page->crawled_at);
        $this->assertNull($sitemap->refresh()->crawled_at);
    }

    public function test_guests_cannot_crawl_a_url_immediately(): void
    {
        $sitemap = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
        ]);

        $this->post('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertRedirect('/login');

        $this->assertNull($page->refresh()->crawled_at);
    }

    public function test_a_sitemap_can_be_crawled_immediately_from_the_index(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(
                'https://example.com/a',
                'https://example.com/b',
            ), 200),
            'https://example.com/a' => Http::response('alpha', 200),
            'https://example.com/b' => Http::response('bravo', 200),
            'https://example.com/other.xml' => Http::response($this->urlset('https://example.com/other'), 200),
            'https://example.com/other' => Http::response('other', 200),
        ]);

        $sitemap = Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Pending,
        ]);
        $other = Sitemap::factory()->create([
            'url' => 'https://example.com/other.xml',
            'status' => SitemapStatus::Pending,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('今すぐ巡回')
            ->assertSee('data-sitemap-crawl', false)
            ->assertSee('data-sitemap-crawl-notice', false)
            ->assertSee(route('manager.sitemaps.crawl', $sitemap), false)
            ->assertSee(route('manager.sitemaps.crawl', $other), false);

        $response = $this->actingAs($user)
            ->postJson(route('manager.sitemaps.crawl', $sitemap));

        $sitemap->refresh();

        $response
            ->assertOk()
            ->assertJson([
                'ran' => true,
                'status_label' => '巡回済',
                'error_message' => null,
                'pages_count' => 2,
                'crawled_at' => $sitemap->crawled_at?->format('Y-m-d H:i'),
            ]);

        $this->assertSame(SitemapStatus::Completed, $sitemap->status);
        $this->assertSame(
            [null, null],
            $sitemap->pages()->orderBy('id')->pluck('crawled_at')->all(),
        );
        $this->assertSame(SitemapStatus::Pending, $other->refresh()->status);
        $this->assertSame(0, $other->pages()->count());
        Http::assertNotSent(fn ($request): bool => in_array($request->url(), [
            'https://example.com/a',
            'https://example.com/b',
            'https://example.com/other.xml',
        ], true));
    }

    public function test_guests_cannot_crawl_a_sitemap_immediately(): void
    {
        $sitemap = Sitemap::factory()->create();

        $this->post('/manager/sitemaps/'.$sitemap->id.'/crawl')->assertRedirect('/login');

        $this->assertSame(SitemapStatus::Pending, $sitemap->refresh()->status);
        $this->assertSame(0, $sitemap->pages()->count());
    }

    public function test_an_immediate_crawl_syncs_urls_and_leaves_fetching_to_the_scheduled_crawl(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(
                'https://example.com/kept',
                'https://example.com/new',
            ), 200),
            'https://example.com/new' => Http::response('fresh', 200),
        ]);

        $sitemap = Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Completed,
            'absence_limit' => null,
            'crawled_at' => now()->subMinute(),
        ]);
        $kept = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kept',
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
            'crawled_at' => now()->subDay(),
        ]);
        $gone = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/gone',
            'body' => 'gone',
            'body_sha256' => hash('sha256', 'gone'),
            'unlisted_at' => now(),
            'crawled_at' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/crawl')
            ->assertOk()
            ->assertJson([
                'ran' => true,
                'status_label' => '巡回済',
                'error_message' => null,
                'pages_count' => 2,
            ]);

        $kept->refresh();
        $this->assertSame('kept', $kept->body);
        $this->assertTrue($sitemap->refresh()->crawled_at?->greaterThan(now()->subSeconds(5)));
        $new = SitemapPage::query()->where('url', 'https://example.com/new')->firstOrFail();
        $this->assertNull($new->crawled_at);
        $this->assertSame('gone', $gone->refresh()->body);
        Http::assertSentCount(1);

        (new CrawlDueSitemapPages(0, 3600))->run();

        $this->assertSame('fresh', $new->refresh()->body);
        $this->assertSame('kept', $kept->refresh()->body);
        Http::assertNotSent(fn ($request): bool => in_array($request->url(), [
            'https://example.com/kept',
            'https://example.com/gone',
        ], true));
    }

    public function test_the_crawl_button_is_enabled_for_every_sitemap(): void
    {
        $done = Sitemap::factory()->create([
            'status' => SitemapStatus::Completed,
        ]);

        $html = $this->actingAs(User::factory()->create())
            ->get('/manager/sitemap')
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-url="'.preg_quote(route('manager.sitemaps.crawl', $done), '/').'"\s+disabled/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-sitemap-status class="px-3 py-2">巡回済</',
            $html,
        );
    }

    public function test_an_immediate_crawl_retries_a_failed_sitemap(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/a'), 200),
            'https://example.com/a' => Http::response('alpha', 200),
        ]);

        $sitemap = Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Failed,
            'error_message' => 'sitemap.xml の取得に失敗しました（HTTP 404）。',
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/crawl')
            ->assertOk()
            ->assertJson([
                'ran' => true,
                'status_label' => '巡回済',
                'error_message' => null,
                'pages_count' => 1,
            ]);

        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
        $this->assertNull($sitemap->error_message);
        $this->assertSame(['https://example.com/a'], $sitemap->pages()->pluck('url')->all());
    }

    public function test_an_immediate_crawl_keeps_stored_bodies_when_a_url_returns(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/back'), 200),
        ]);

        $sitemap = $this->completedSitemap();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/back',
            'body' => 'stored-body',
            'body_sha256' => hash('sha256', 'stored-body'),
            'article_text' => '採用本文',
            'unlisted_at' => now(),
            'absence_count' => 2,
        ]);
        $page->refresh();
        DB::table('sitemap_page_embeddings')->insert([
            'sitemap_page_id' => $page->id,
            'embedding' => json_encode(array_fill(0, 1536, 0.1)),
            'article_text_sha256' => $page->article_text_sha256,
            'embedded_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs(User::factory()->create())
            ->postJson('/manager/sitemaps/'.$sitemap->id.'/crawl')
            ->assertOk()
            ->assertJson([
                'ran' => true,
                'pages_count' => 1,
            ]);

        foreach (DB::getQueryLog() as $query) {
            $this->assertIsString($query['query']);
            $this->assertDoesNotMatchRegularExpression('/[`"]body[`"]/', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/[`"]article_text[`"]/', $query['query']);
        }

        $page->refresh();
        $this->assertNull($page->unlisted_at);
        $this->assertSame(0, $page->absence_count);
        $this->assertSame('stored-body', $page->body);
        $this->assertSame('採用本文', $page->articleText());
        $this->assertSame(hash('sha256', '採用本文'), $page->article_text_sha256);
        $this->assertSame(1, $page->embeddings()->count());
    }

    public function test_a_page_from_another_sitemap_cannot_be_crawled_immediately(): void
    {
        $sitemap = Sitemap::factory()->create();
        $other = Sitemap::factory()->create();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $other->id,
            'url' => 'https://example.com/a',
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/manager/sitemaps/'.$sitemap->id.'/pages/'.$page->id.'/crawl')
            ->assertNotFound();

        $this->assertNull($page->refresh()->crawled_at);
    }

    public function test_a_dropped_url_stays_stored_and_leaves_search_without_changing_the_toggle(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/kept'), 200),
        ]);

        $sitemap = $this->completedSitemap();
        $sitemap->absence_limit = 3;
        $sitemap->save();
        $kept = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kept',
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
            'http_status' => 404,
        ]);
        $dropped = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/dropped',
            'searchable' => false,
            'body' => 'old',
            'body_sha256' => hash('sha256', 'old'),
            'http_status' => 200,
        ]);

        $this->refreshSitemap($sitemap);

        $dropped->refresh();
        $this->assertSame(1, $dropped->absence_count);
        $this->assertNotNull($dropped->unlisted_at);
        $this->assertFalse($dropped->searchable);
        $this->assertSame('old', $dropped->body);
        $this->assertNull($kept->refresh()->unlisted_at);
        $this->assertSame(404, $kept->http_status);
        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
        $this->assertNull($sitemap->error_message);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.com/dropped');
        $this->assertFalse(SitemapPage::query()->forSearch()->whereKey($dropped->id)->exists());
        $this->assertFalse(SitemapPage::query()->forSearch()->whereKey($kept->id)->exists());
    }

    public function test_a_url_absent_from_three_successful_refreshes_is_deleted(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/kept'), 200),
        ]);

        $sitemap = $this->completedSitemap();
        $sitemap->absence_limit = 3;
        $sitemap->save();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kept',
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
        ]);
        $gone = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/gone',
            'searchable' => true,
            'body' => 'gone',
            'body_sha256' => hash('sha256', 'gone'),
        ]);

        $this->refreshSitemap($sitemap);
        $gone->refresh();
        $this->assertSame(1, $gone->absence_count);
        $listedAt = $gone->unlisted_at;
        $this->assertNotNull($listedAt);

        $this->refreshSitemap($sitemap);
        $gone->refresh();
        $this->assertSame(2, $gone->absence_count);
        $this->assertTrue($gone->unlisted_at?->equalTo($listedAt));
        $this->assertSame('gone', $gone->body);
        $this->assertTrue($gone->searchable);

        $this->refreshSitemap($sitemap);

        $this->assertDatabaseMissing('sitemap_pages', ['id' => $gone->id]);
        $this->assertDatabaseHas('sitemap_pages', ['url' => 'https://example.com/kept']);
        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
    }

    public function test_a_relisted_url_with_a_body_hash_is_not_fetched_again(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(
                'https://example.com/hashed',
            ), 200),
            'https://example.com/hashed' => Http::response('changed-hash', 200),
        ]);

        $sitemap = $this->completedSitemap();
        $hashed = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/hashed',
            'searchable' => false,
            'body' => 'hash-body',
            'body_sha256' => hash('sha256', 'hash-body'),
            'unlisted_at' => now()->subDay(),
            'absence_count' => 2,
            'crawled_at' => now()->subDays(2),
        ]);

        $this->refreshSitemap($sitemap);

        $hashed->refresh();
        $this->assertNull($hashed->unlisted_at);
        $this->assertSame(0, $hashed->absence_count);
        $this->assertFalse($hashed->searchable);
        $this->assertSame('hash-body', $hashed->body);
        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.com/hashed');
    }

    public function test_a_relisted_url_without_a_body_hash_is_crawled(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/fresh'), 200),
            'https://example.com/fresh' => Http::response('fresh-body', 200),
        ]);

        $sitemap = $this->completedSitemap();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/fresh',
            'searchable' => false,
            'unlisted_at' => now()->subDay(),
            'absence_count' => 2,
        ]);

        $this->refreshSitemap($sitemap);

        $page->refresh();
        $this->assertNull($page->unlisted_at);
        $this->assertSame(0, $page->absence_count);
        $this->assertFalse($page->searchable);
        $this->assertSame('fresh-body', $page->body);
        $this->assertSame(hash('sha256', 'fresh-body'), $page->body_sha256);
        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
    }

    public function test_a_failed_or_invalid_refresh_does_not_drop_urls(): void
    {
        $crawledAt = now()->subDays(2)->startOfSecond();
        $sitemap = $this->completedSitemap();
        $sitemap->crawled_at = $crawledAt;
        $sitemap->save();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
            'searchable' => false,
            'body' => 'alpha',
            'body_sha256' => hash('sha256', 'alpha'),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('missing', 503),
        ]);

        $this->refreshSitemap($sitemap);

        $page->refresh();
        $this->assertNull($page->unlisted_at);
        $this->assertSame(0, $page->absence_count);
        $this->assertFalse($page->searchable);
        $this->assertSame('alpha', $page->body);
        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
        $this->assertNull($sitemap->error_message);
        $this->assertTrue($crawledAt->equalTo($sitemap->crawled_at));

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('<html></html>', 200),
        ]);

        $this->refreshSitemap($sitemap);

        $page->refresh();
        $this->assertNull($page->unlisted_at);
        $this->assertSame(0, $page->absence_count);
        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
        $this->assertNull($sitemap->error_message);
        $this->assertTrue($crawledAt->equalTo($sitemap->crawled_at));
    }

    public function test_a_failed_child_sitemap_refresh_does_not_drop_urls(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapIndex(
                'https://example.com/child.xml',
            ), 200),
            'https://example.com/child.xml' => Http::response('missing', 404),
        ]);

        $sitemap = $this->completedSitemap();
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
            'body' => 'alpha',
            'body_sha256' => hash('sha256', 'alpha'),
        ]);

        $this->refreshSitemap($sitemap);

        $this->assertNull($page->refresh()->unlisted_at);
        $this->assertSame(0, $page->absence_count);
        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
        $this->assertNull($sitemap->error_message);
    }

    public function test_a_refresh_adds_new_urls_and_leaves_listed_pages_unchanged(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(
                'https://example.com/kept',
                'https://example.com/new',
            ), 200),
            'https://example.com/kept' => Http::response('should-not-replace', 200),
            'https://example.com/new' => Http::response('new-body', 201),
        ]);

        $sitemap = $this->completedSitemap();
        $sitemap->crawled_at = now()->subDay();
        $sitemap->save();
        $kept = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kept',
            'searchable' => false,
            'body' => 'kept-body',
            'body_sha256' => hash('sha256', 'kept-body'),
            'http_status' => 200,
            'crawled_at' => now(),
        ]);

        $this->refreshSitemap($sitemap);

        $kept->refresh();
        $this->assertSame('kept-body', $kept->body);
        $this->assertSame(200, $kept->http_status);
        $this->assertFalse($kept->searchable);
        $this->assertNull($kept->unlisted_at);

        $new = SitemapPage::query()->where('url', 'https://example.com/new')->firstOrFail();
        $this->assertTrue($new->searchable);
        $this->assertNull($new->unlisted_at);
        $this->assertSame(0, $new->absence_count);
        $this->assertSame('new-body', $new->body);
        $this->assertSame(201, $new->http_status);
        $this->assertSame(SitemapStatus::Completed, $sitemap->refresh()->status);
        $this->assertTrue($sitemap->crawled_at?->greaterThan(now()->subMinute()));

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.com/kept');
    }

    public function test_returning_to_the_sitemap_resets_the_absence_count(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::sequence()
                ->push($this->urlset('https://example.com/a', 'https://example.com/other'), 200)
                ->push($this->urlset('https://example.com/other'), 200),
            'https://example.com/other' => Http::response('other', 200),
        ]);

        $sitemap = $this->completedSitemap();
        $sitemap->absence_limit = 3;
        $sitemap->save();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/other',
            'body' => 'other',
            'body_sha256' => hash('sha256', 'other'),
        ]);
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
            'body' => 'alpha',
            'body_sha256' => hash('sha256', 'alpha'),
            'unlisted_at' => now()->subDays(2),
            'absence_count' => 2,
        ]);

        $this->refreshSitemap($sitemap);
        $page->refresh();
        $this->assertNull($page->unlisted_at);
        $this->assertSame(0, $page->absence_count);

        $this->refreshSitemap($sitemap);
        $page->refresh();
        $this->assertSame(1, $page->absence_count);
        $this->assertNotNull($page->unlisted_at);
        $this->assertSame('alpha', $page->body);
    }

    public function test_search_includes_only_listed_searchable_pages_with_a_body_except_http_404(): void
    {
        $sitemap = Sitemap::factory()->create();
        $listed = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/listed',
            'searchable' => true,
            'body' => 'listed',
            'http_status' => 200,
        ]);
        $unknown = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/unknown',
            'searchable' => true,
            'body' => 'unknown',
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/missing',
            'searchable' => true,
            'body' => 'missing',
            'http_status' => 404,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/unlisted',
            'searchable' => true,
            'body' => 'unlisted',
            'unlisted_at' => now(),
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/off',
            'searchable' => false,
            'body' => 'off',
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/empty',
            'searchable' => true,
        ]);

        $this->assertSame(
            [$listed->id, $unknown->id],
            SitemapPage::query()->forSearch()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_the_url_list_shows_when_a_page_left_the_sitemap(): void
    {
        $sitemap = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/gone',
            'searchable' => true,
            'unlisted_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('https://example.com/gone')
            ->assertSee('サイトマップから外れた')
            ->assertSee('aria-checked="true"', false)
            ->assertDontSee('data-search-state', false)
            ->assertSee('検索対象');
    }

    public function test_the_sitemap_index_count_ignores_urls_that_left_the_sitemap(): void
    {
        $sitemap = Sitemap::factory()->create([
            'status' => SitemapStatus::Completed,
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/listed',
            'crawled_at' => now(),
        ]);
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/unlisted',
            'unlisted_at' => now(),
            'crawled_at' => now(),
        ]);

        $href = route('manager.sitemaps.show', $sitemap);

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('<a class="underline underline-offset-4" href="'.$href.'">1</a>', false);
    }

    public function test_a_zero_published_count_is_plain_text(): void
    {
        $empty = Sitemap::factory()->create();
        $listed = Sitemap::factory()->create();
        SitemapPage::query()->create([
            'sitemap_id' => $listed->id,
            'url' => 'https://example.com/listed',
        ]);

        $emptyHref = route('manager.sitemaps.show', $empty);
        $listedHref = route('manager.sitemaps.show', $listed);

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertDontSee('<a class="underline underline-offset-4" href="'.$emptyHref.'">0</a>', false)
            ->assertSee('<a class="underline underline-offset-4" href="'.$listedHref.'">1</a>', false);
    }

    public function test_inspection_reads_pending_sitemaps_and_completed_sitemaps_read_a_day_ago(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/old.xml' => Http::response($this->urlset('https://example.com/old'), 200),
            'https://example.com/pending.xml' => Http::response($this->urlset('https://example.com/pending'), 200),
        ]);

        Sitemap::factory()->create([
            'url' => 'https://example.com/old.xml',
            'status' => SitemapStatus::Completed,
            'crawled_at' => now()->subDay(),
        ]);
        Sitemap::factory()->create([
            'url' => 'https://example.com/recent.xml',
            'status' => SitemapStatus::Completed,
            'crawled_at' => now()->subDay()->addMinute(),
        ]);
        Sitemap::factory()->create([
            'url' => 'https://example.com/pending.xml',
            'status' => SitemapStatus::Pending,
        ]);
        Sitemap::factory()->create([
            'url' => 'https://example.com/failed.xml',
            'status' => SitemapStatus::Failed,
        ]);

        $this->artisan('sitemaps:inspect')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/old.xml');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/pending.xml');
        $this->assertSame(0, SitemapPage::query()->whereNotNull('crawled_at')->count());
    }

    public function test_a_failed_inspection_is_logged(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('missing', 404),
        ]);

        $sitemap = Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Pending,
        ]);

        $this->artisan('sitemaps:inspect')->assertSuccessful();

        $log = CrawlErrorLog::query()->sole();
        $this->assertSame(CrawlErrorKind::Sitemap, $log->kind);
        $this->assertSame($sitemap->id, $log->sitemap_id);
        $this->assertNull($log->sitemap_page_id);
        $this->assertSame('https://example.com/sitemap.xml', $log->url);
        $this->assertSame('sitemap.xml の取得に失敗しました（HTTP 404）。', $log->message);

        $sitemap->delete();

        $this->assertNull($log->refresh()->sitemap_id);
        $this->assertSame('https://example.com/sitemap.xml', $log->url);
    }

    public function test_the_three_steps_are_scheduled(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $expressions = collect(app(Schedule::class)->events())
            ->filter(fn (mixed $event): bool => is_object($event) && isset($event->command) && is_string($event->command))
            ->mapWithKeys(fn (object $event): array => [
                preg_replace('/^.*artisan.? /', '', $event->command) => $event->expression,
            ])
            ->all();

        $this->assertSame([
            'sitemaps:inspect' => '0 * * * *',
            'sitemaps:crawl' => '* * * * *',
            'sitemaps:embed' => '* * * * *',
        ], $expressions);
        $this->assertSame(1, CrawlDueSitemapPages::INTERVAL_SECONDS);
    }

    public function test_an_immediate_crawl_returns_the_current_state_while_another_inspection_runs(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $sitemap = Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Pending,
        ]);
        $lock = Cache::lock(InspectSitemap::lockKey($sitemap->id), InspectSitemap::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        $this->actingAs(User::factory()->create())
            ->postJson(route('manager.sitemaps.crawl', $sitemap))
            ->assertOk()
            ->assertJson([
                'ran' => false,
                'status_label' => '確認待ち',
                'error_message' => null,
                'pages_count' => 0,
            ]);

        $this->assertSame(SitemapStatus::Pending, $sitemap->refresh()->status);
        $this->assertSame(0, $sitemap->pages()->count());
        Http::assertNothingSent();

        $lock->release();
    }

    public function test_an_immediate_crawl_keeps_a_failed_sitemap_while_another_inspection_runs(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $message = 'sitemap.xml の取得に失敗しました（HTTP 404）。';
        $sitemap = Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Failed,
            'error_message' => $message,
        ]);
        $updatedAt = $sitemap->fresh()?->updated_at;
        $lock = Cache::lock(InspectSitemap::lockKey($sitemap->id), InspectSitemap::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        $this->actingAs(User::factory()->create())
            ->postJson(route('manager.sitemaps.crawl', $sitemap))
            ->assertOk()
            ->assertJson([
                'ran' => false,
                'status_label' => '取得失敗',
                'error_message' => $message,
                'pages_count' => 0,
            ]);

        $sitemap->refresh();
        $this->assertSame(SitemapStatus::Failed, $sitemap->status);
        $this->assertSame($message, $sitemap->error_message);
        $this->assertTrue($sitemap->updated_at?->equalTo($updatedAt));
        Http::assertNothingSent();

        $lock->release();
    }

    public function test_the_scheduled_crawl_skips_a_sitemap_another_inspection_is_reading(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/free.xml' => Http::response($this->urlset('https://example.com/free'), 200),
            'https://example.com/free' => Http::response('free', 200),
        ]);

        $busy = Sitemap::factory()->create([
            'url' => 'https://example.com/busy.xml',
            'status' => SitemapStatus::Pending,
        ]);
        $free = Sitemap::factory()->create([
            'url' => 'https://example.com/free.xml',
            'status' => SitemapStatus::Pending,
        ]);
        $lock = Cache::lock(InspectSitemap::lockKey($busy->id), InspectSitemap::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        $this->processDueSitemaps();

        $this->assertSame(SitemapStatus::Pending, $busy->refresh()->status);
        $this->assertSame(0, $busy->pages()->count());
        $this->assertSame(SitemapStatus::Completed, $free->refresh()->status);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.com/busy.xml');

        $lock->release();
    }

    public function test_a_refresh_during_another_inspection_does_not_count_an_absence(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/b'), 200),
            'https://example.com/b' => Http::response('beta', 200),
        ]);

        $sitemap = $this->completedSitemap();
        $sitemap->update(['absence_limit' => 2]);
        $page = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/a',
            'body' => 'alpha',
            'body_sha256' => hash('sha256', 'alpha'),
            'crawled_at' => now()->subDay(),
        ]);
        $lock = Cache::lock(InspectSitemap::lockKey($sitemap->id), InspectSitemap::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        $this->refreshSitemap($sitemap);

        $this->assertSame(0, $page->refresh()->absence_count);
        Http::assertNothingSent();

        $lock->release();
        $this->refreshSitemap($sitemap);

        $this->assertSame(1, $page->refresh()->absence_count);
        $this->assertDatabaseHas('sitemap_pages', ['url' => 'https://example.com/b']);
    }

    public function test_inspection_does_nothing_for_a_failed_or_invalid_sitemap(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $failed = Sitemap::factory()->create([
            'url' => 'https://example.com/failed.xml',
            'status' => SitemapStatus::Failed,
        ]);
        $invalid = Sitemap::factory()->create([
            'url' => 'https://example.com/invalid.xml',
            'status' => SitemapStatus::Invalid,
        ]);

        (new InspectSitemap($failed->id))->handle(new SitemapUrlsetReader);
        (new InspectSitemap($invalid->id))->handle(new SitemapUrlsetReader);

        $this->assertSame(SitemapStatus::Failed, $failed->refresh()->status);
        $this->assertSame(SitemapStatus::Invalid, $invalid->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_sitemap_index_confirms_before_deleting_a_sitemap(): void
    {
        Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('type="button"', false)
            ->assertSee('data-sitemap-delete', false)
            ->assertSee('data-sitemap-url="https://example.com/sitemap.xml"', false)
            ->assertSee('data-sitemap-delete-dialog', false)
            ->assertSee('このサイトマップを削除してよろしいですか？')
            ->assertSee('掲載されている URL も削除されます。')
            ->assertSee('キャンセル')
            ->assertSee('削除する')
            ->assertDontSee('type="submit">削除', false);
    }

    public function test_the_sitemap_index_shows_the_absence_limit_with_an_edit_control(): void
    {
        Sitemap::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('削除までの回数')
            ->assertSeeInOrder(['削除までの回数', '巡回日時'])
            ->assertSee('data-sitemap-crawled-at', false)
            ->assertSee('aria-label="削除までの回数の説明"', false)
            ->assertSee('サイトマップを読み直して何回続けて見つからなければ', false)
            ->assertSee('回数は最初からになります。', false)
            ->assertSee('「削除しない」にすると削除しません。', false)
            ->assertSee('data-absence-limit="1"', false)
            ->assertSee('data-absence-label>1回', false)
            ->assertSee('削除しない')
            ->assertSee('aria-label="削除までの回数を変更"', false)
            ->assertSee('hidden', false);
    }

    public function test_the_absence_limit_can_be_changed_from_the_sitemap_index(): void
    {
        $sitemap = Sitemap::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/manager/sitemaps/'.$sitemap->id.'/absence-limit', [
                'absence_limit' => 5,
            ])
            ->assertOk()
            ->assertExactJson(['absence_limit' => 5]);

        $this->assertSame(5, $sitemap->refresh()->absence_limit);

        $this->actingAs($user)
            ->patchJson('/manager/sitemaps/'.$sitemap->id.'/absence-limit', [
                'absence_limit' => null,
            ])
            ->assertOk()
            ->assertExactJson(['absence_limit' => null]);

        $this->assertNull($sitemap->refresh()->absence_limit);

        $this->actingAs($user)
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('data-absence-limit=""', false)
            ->assertSee('削除しない');
    }

    public function test_guests_cannot_change_the_absence_limit(): void
    {
        $sitemap = Sitemap::factory()->create();

        $this->patchJson('/manager/sitemaps/'.$sitemap->id.'/absence-limit', [
            'absence_limit' => 2,
        ])->assertUnauthorized();

        $this->assertSame(Sitemap::DEFAULT_ABSENCE_LIMIT, $sitemap->refresh()->absence_limit);
    }

    public function test_an_absence_limit_outside_one_to_five_is_rejected(): void
    {
        $sitemap = Sitemap::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/manager/sitemaps/'.$sitemap->id.'/absence-limit', [
                'absence_limit' => 6,
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->patchJson('/manager/sitemaps/'.$sitemap->id.'/absence-limit', [
                'absence_limit' => 0,
            ])
            ->assertUnprocessable();

        $this->assertSame(Sitemap::DEFAULT_ABSENCE_LIMIT, $sitemap->refresh()->absence_limit);
    }

    public function test_a_url_is_deleted_when_absences_reach_the_sitemap_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/kept'), 200),
        ]);

        $sitemap = $this->completedSitemap();
        $sitemap->absence_limit = 1;
        $sitemap->save();
        SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/kept',
            'body' => 'kept',
            'body_sha256' => hash('sha256', 'kept'),
        ]);
        $gone = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/gone',
            'body' => 'gone',
            'body_sha256' => hash('sha256', 'gone'),
        ]);

        $this->refreshSitemap($sitemap);

        $this->assertDatabaseMissing('sitemap_pages', ['id' => $gone->id]);
        $this->assertDatabaseHas('sitemap_pages', ['url' => 'https://example.com/kept']);
    }

    public function test_urls_that_leave_the_sitemap_are_kept_when_deletion_is_off(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlset('https://example.com/kept'), 200),
        ]);

        $sitemap = $this->completedSitemap();
        $sitemap->absence_limit = null;
        $sitemap->save();
        $gone = SitemapPage::query()->create([
            'sitemap_id' => $sitemap->id,
            'url' => 'https://example.com/gone',
            'body' => 'gone',
            'body_sha256' => hash('sha256', 'gone'),
            'unlisted_at' => now()->subDays(4),
            'absence_count' => 4,
        ]);

        $this->refreshSitemap($sitemap);

        $gone->refresh();
        $this->assertSame(5, $gone->absence_count);
        $this->assertNotNull($gone->unlisted_at);
        $this->assertSame('gone', $gone->body);
    }

    private function completedSitemap(): Sitemap
    {
        return Sitemap::factory()->create([
            'url' => 'https://example.com/sitemap.xml',
            'status' => SitemapStatus::Completed,
        ]);
    }

    private function refreshSitemap(Sitemap $sitemap): void
    {
        (new InspectSitemap($sitemap->id))->handle(new SitemapUrlsetReader);
        (new CrawlDueSitemapPages(0, 3600))->run();
    }

    private function processDueSitemaps(): void
    {
        $this->artisan('sitemaps:inspect')->assertSuccessful();
        (new CrawlDueSitemapPages(0, 3600))->run();
    }

    /**
     * @return array{responses: array<string, Response>, beyond: string}
     */
    private function sitemapDeeperThanTheLimit(): array
    {
        $responses = [
            'https://example.com/within.xml' => Http::response($this->urlset('https://example.com/within'), 200),
        ];
        $beyond = 'https://example.com/beyond.xml';
        $child = $beyond;

        for ($depth = InspectSitemap::MAX_DEPTH; $depth >= 2; $depth--) {
            $url = 'https://example.com/level-'.$depth.'.xml';
            $responses[$url] = Http::response($this->sitemapIndex($child), 200);
            $child = $url;
        }

        $responses['https://example.com/sitemap.xml'] = Http::response($this->sitemapIndex(
            'https://example.com/within.xml',
            $child,
        ), 200);

        return [
            'responses' => $responses,
            'beyond' => $beyond,
        ];
    }

    /**
     * @return array<string, Response>
     */
    private function sitemapAtTheDepthLimit(): array
    {
        $leaf = 'https://example.com/level-'.InspectSitemap::MAX_DEPTH.'.xml';
        $responses = [
            $leaf => Http::response($this->urlset('https://example.com/within'), 200),
            'https://example.com/within' => Http::response('within', 200),
        ];
        $child = $leaf;

        for ($depth = InspectSitemap::MAX_DEPTH - 1; $depth >= 2; $depth--) {
            $url = 'https://example.com/level-'.$depth.'.xml';
            $responses[$url] = Http::response($this->sitemapIndex($child), 200);
            $child = $url;
        }

        $responses['https://example.com/sitemap.xml'] = Http::response($this->sitemapIndex($child), 200);

        return $responses;
    }

    private function urlset(string ...$locs): string
    {
        $urls = '';

        foreach ($locs as $loc) {
            $urls .= '<url><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc></url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$urls
            .'</urlset>';
    }

    private function sitemapIndex(string ...$locs): string
    {
        $sitemaps = '';

        foreach ($locs as $loc) {
            $sitemaps .= '<sitemap><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc></sitemap>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$sitemaps
            .'</sitemapindex>';
    }
}
