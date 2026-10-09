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
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('ログイン');
        $response->assertDontSee('ログインを保持する');
        $response->assertDontSee('パスワードを忘れた');
    }

    public function test_users_can_authenticate_and_reach_the_manager(): void
    {
        $user = User::factory()->create([
            'email' => 'user1@example.com',
            'password' => '123456789',
        ]);

        $response = $this->post('/login', [
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

        $response = $this->from('/login')->post('/login', [
            'email' => 'user1@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
    }

    public function test_guests_are_redirected_from_the_manager(): void
    {
        $this->get('/manager')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_the_manager(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/manager')
            ->assertOk()
            ->assertSee('ダッシュボード')
            ->assertSee('ログアウト');
    }

    public function test_authenticated_users_are_redirected_from_the_login_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/login')
            ->assertRedirect('/manager');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }
}
