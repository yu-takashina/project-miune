<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_are_redirected_from_api_keys(): void
    {
        $apiKey = ApiKey::factory()->create();

        $this->get('/manager/api-keys')->assertRedirect('/login');
        $this->post('/manager/api-keys')->assertRedirect('/login');
        $this->delete('/manager/api-keys/'.$apiKey->id)->assertRedirect('/login');

        $this->assertModelExists($apiKey);
    }

    public function test_the_header_links_to_api_keys(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('href="'.route('manager.api-keys.index').'"', false)
            ->assertSee('>APIキー</a>', false)
            ->assertDontSee('href="'.route('manager.api-keys.index').'"'."\n                aria-current=\"page\"", false);

        $content = $this->get('/manager/api-keys')->assertOk()->getContent();
        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('manager.api-keys.index'), '/').'"\s+aria-current="page"/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="'.preg_quote(route('sitemap.index'), '/').'"\s+aria-current="page"/',
            $content,
        );
    }

    public function test_the_index_lists_the_full_key(): void
    {
        $older = ApiKey::factory()->create([
            'token' => 'older+key-with_symbols-012345678',
        ]);
        $newer = ApiKey::factory()->create([
            'token' => 'newer+key-with_symbols-012345678',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/manager/api-keys')
            ->assertOk()
            ->assertSeeInOrder([$newer->token, $older->token])
            ->assertSee('older+key-with_symbols-012345678', false)
            ->assertSee('newer+key-with_symbols-012345678', false)
            ->assertDontSee('発行された APIキーはありません。')
            ->assertDontSee('この画面を離れると再表示できません。')
            ->assertDontSee('名前')
            ->assertSee('最終利用日')
            ->assertDontSee('発行日時')
            ->assertSee('data-api-key-copy', false)
            ->assertSee('aria-label="コピー"', false)
            ->assertSee('data-api-key-token="'.$newer->token.'"', false);
    }

    public function test_an_empty_list_says_there_are_no_keys(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/manager/api-keys')
            ->assertOk()
            ->assertSee('発行された APIキーはありません。')
            ->assertSee('>発行</button>', false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('data-api-key-delete', false)
            ->assertDontSee('data-api-key-copy', false)
            ->assertDontSee('この画面を離れると再表示できません。');
    }

    public function test_a_manager_can_issue_a_key(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/manager/api-keys')
            ->assertRedirect(route('manager.api-keys.index'));

        $apiKey = ApiKey::query()->first();
        $this->assertInstanceOf(ApiKey::class, $apiKey);
        $this->assertSame(ApiKey::LENGTH, strlen($apiKey->token));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9+_-]{32}\z/', $apiKey->token);

        $this->get('/manager/api-keys')
            ->assertOk()
            ->assertSee($apiKey->token, false)
            ->assertSee('未使用')
            ->assertDontSee('発行日時')
            ->assertDontSee('この画面を離れると再表示できません。');
    }

    public function test_the_index_shows_the_last_used_date(): void
    {
        ApiKey::factory()->create([
            'token' => 'unused+key-with_symbols-01234567',
        ]);
        ApiKey::factory()->create([
            'token' => 'used+key-with_symbols-0123456789',
            'last_used_at' => '2026-04-01 23:15:00',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/manager/api-keys')
            ->assertOk()
            ->assertSeeInOrder([
                'used+key-with_symbols-0123456789',
                '2026-04-01',
                'unused+key-with_symbols-01234567',
                '未使用',
            ])
            ->assertDontSee('発行日時')
            ->assertDontSee('23:15');
    }

    public function test_the_index_confirms_before_invalidating_a_key(): void
    {
        $apiKey = ApiKey::factory()->create([
            'token' => 'confirm+key-with_symbols-0123456',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/manager/api-keys')
            ->assertOk()
            ->assertSee('type="button"', false)
            ->assertSee('data-api-key-delete', false)
            ->assertSee('data-api-key-token="'.$apiKey->token.'"', false)
            ->assertSee('data-api-key-delete-dialog', false)
            ->assertSee('この APIキーを無効化してよろしいですか？')
            ->assertSee('無効化すると元に戻せません。')
            ->assertSee('キャンセル')
            ->assertSee('無効化する')
            ->assertDontSee('type="submit">無効化', false);
    }

    public function test_a_manager_can_invalidate_a_key(): void
    {
        $keep = ApiKey::factory()->create();
        $drop = ApiKey::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete('/manager/api-keys/'.$drop->id)
            ->assertRedirect(route('manager.api-keys.index'));

        $this->assertModelMissing($drop);
        $this->assertModelExists($keep);
    }
}
