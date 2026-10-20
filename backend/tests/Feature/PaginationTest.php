<?php

namespace Tests\Feature;

use App\Models\Sitemap;
use App\Models\SitemapPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_url_list_pagination_is_in_japanese(): void
    {
        $sitemap = Sitemap::factory()->create();

        for ($index = 1; $index <= 51; $index++) {
            SitemapPage::query()->create([
                'sitemap_id' => $sitemap->id,
                'url' => 'https://example.com/'.$index,
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemaps/'.$sitemap->id)
            ->assertOk()
            ->assertSee('前へ')
            ->assertSee('次へ')
            ->assertSee('aria-label="ページ送り"', false)
            ->assertSee('aria-label="2 ページへ"', false)
            ->assertDontSee('pagination.')
            ->assertDontSee('Pagination Navigation');
    }
}
