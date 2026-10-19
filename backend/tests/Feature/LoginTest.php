<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('ログイン');
        $this->get('/login')->assertNotFound();
        $response->assertDontSee('ログインを保持する');
        $response->assertDontSee('パスワードを忘れた');
    }

    public function test_users_can_authenticate_and_reach_the_manager(): void
    {
        $user = User::factory()->create([
            'email' => 'user1@example.com',
            'password' => '123456789',
        ]);

        $response = $this->post('/', [
            'email' => 'user1@example.com',
            'password' => '123456789',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/manager');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'user1@example.com',
            'password' => '123456789',
        ]);

        $response = $this->from('/')->post('/', [
            'email' => 'user1@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/');
        $response->assertSessionHasErrors('email');
    }

    public function test_guests_are_redirected_from_the_manager(): void
    {
        $this->get('/manager')->assertRedirect('/');
        $this->get('/manager/sitemap')->assertRedirect('/');
    }

    public function test_the_manager_redirects_to_the_sitemap_index(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/manager')
            ->assertRedirect(route('sitemap.index'));
    }

    public function test_authenticated_users_can_view_the_sitemap_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/manager/sitemap')
            ->assertOk()
            ->assertSee('aria-label="グローバルナビゲーション"', false)
            ->assertSee('href="'.route('sitemap.index').'"', false)
            ->assertSee('サイトマップ')
            ->assertSee('ログアウト')
            ->assertDontSee($user->name);
    }

    public function test_authenticated_users_are_redirected_from_the_login_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect('/manager');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
