<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_are_redirected_from_the_search_page(): void
    {
        $this->get('/manager/search?q=梅雨')->assertRedirect('/login');
    }

    public function test_the_old_search_url_is_gone(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/search?q=梅雨')
            ->assertNotFound();
    }

    public function test_the_header_search_box_submits_to_the_search_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('action="'.url('/manager/search').'"', false)
            ->assertSee('type="search"', false)
            ->assertSee('name="q"', false)
            ->assertSee('placeholder="検索"', false)
            ->assertSee('M9 3.5a5.5 5.5 0 1 0 0 11', false)
            ->assertDontSee('>検索</button>', false);
    }

    public function test_the_search_page_can_be_rendered(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/manager/search?q=梅雨')
            ->assertOk()
            ->assertSee('<title>検索 - '.e(config('app.name')).'</title>', false)
            ->assertSee('<h1 class="text-2xl font-medium">「梅雨」の検索結果</h1>', false)
            ->assertSee('id="header-search"', false)
            ->assertSee('id="content-search"', false)
            ->assertSee('value="梅雨"', false);
    }

    public function test_the_search_page_without_a_query_uses_a_plain_heading(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/manager/search')
            ->assertOk()
            ->assertSee('<h1 class="text-2xl font-medium">検索</h1>', false)
            ->assertDontSee('の検索結果', false)
            ->assertSee('id="content-search"', false);

        $this->get('/manager/search?q=%20%20')
            ->assertOk()
            ->assertSee('<h1 class="text-2xl font-medium">検索</h1>', false)
            ->assertDontSee('の検索結果', false);
    }
}
