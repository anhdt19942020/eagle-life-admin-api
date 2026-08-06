<?php

// db-refresh-allow: matches existing Feature suite; phpunit uses isolated sqlite :memory: via DatabaseMigrations

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use DatabaseMigrations;

    public function test_login_succeeds_with_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'seller01',
            'password' => 'password123',
            'status' => true,
        ]);

        $this->postJson('/api/login', [
            'username' => 'seller01',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['access_token', 'user']]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'username' => 'seller01',
            'password' => 'password123',
            'status' => true,
        ]);

        $this->postJson('/api/login', [
            'username' => 'seller01',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('data.username.0', 'Tài khoản hoặc mật khẩu không chính xác.');
    }

    public function test_login_rejects_unknown_username(): void
    {
        $this->postJson('/api/login', [
            'username' => 'missing',
            'password' => 'password123',
        ])->assertUnprocessable()
            ->assertJsonPath('data.username.0', 'Tài khoản hoặc mật khẩu không chính xác.');
    }

    public function test_login_rejects_locked_account(): void
    {
        User::factory()->create([
            'username' => 'locked01',
            'password' => 'password123',
            'status' => false,
        ]);

        $this->postJson('/api/login', [
            'username' => 'locked01',
            'password' => 'password123',
        ])->assertUnprocessable()
            ->assertJsonPath('data.username.0', 'Tài khoản của bạn đã bị khóa.');
    }

    public function test_login_requires_username_not_email(): void
    {
        User::factory()->create([
            'username' => 'seller01',
            'email' => 'seller01@example.com',
            'password' => 'password123',
            'status' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => 'seller01@example.com',
            'password' => 'password123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['username'], 'data');
    }
}
