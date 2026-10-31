<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class ReaderSecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email, string $password = 'Incorrect!Password2026'): array
    {
        return ['email' => $email, 'password' => $password];
    }

    public function test_distributed_brute_force_is_limited_by_account_across_source_ips(): void
    {
        $user = User::factory()->create([
            'email' => 'target@example.test',
            'password' => 'Correct!Password2026',
        ]);

        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->postJson('/api/v1/auth/login', $this->login('TARGET@example.test'))
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->postJson('/api/v1/auth/login', $this->login($user->email, 'Correct!Password2026'))
            ->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->travel(61)->minutes();
        $this->postJson('/api/v1/auth/login', $this->login($user->email, 'Correct!Password2026'))
            ->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_password_spraying_many_accounts_from_one_ip_is_limited(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
                ->postJson('/api/v1/auth/login', $this->login('unknown-'.$attempt.'@example.test'))
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->postJson('/api/v1/auth/login', $this->login('another@example.test'))
            ->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reset_token_guessing_is_limited_and_success_revokes_all_devices(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-target@example.test',
            'password' => 'Old!Password2026',
        ]);
        $token = Password::broker('users')->createToken($user);
        $user->createToken('reader-phone', ['reader:read']);
        $user->createToken('reader-tablet', ['reader:read']);

        $payload = [
            'email' => $user->email,
            'password' => 'New!Password2026',
            'password_confirmation' => 'New!Password2026',
        ];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/password/reset', [
                ...$payload, 'token' => 'incorrect-'.$attempt,
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/password/reset', [
            ...$payload, 'token' => $token,
        ])->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertTrue(Hash::check('Old!Password2026', $user->fresh()->password));

        // The original broker token also expires after 60 minutes. Generate a
        // fresh authorized token rather than relying on an expired credential.
        $this->travel(61)->minutes();
        $freshToken = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/auth/password/reset', [
            ...$payload, 'token' => $freshToken,
        ])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(Hash::check('New!Password2026', $user->fresh()->password));
        $this->postJson('/api/v1/auth/password/reset', [
            ...$payload, 'token' => $token,
        ])->assertUnprocessable();
    }

    public function test_unknown_account_login_is_rate_limited_without_creating_a_token(): void
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson('/api/v1/auth/login', $this->login('nonexistent@example.test'))
                ->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', $this->login('nonexistent@example.test'))
            ->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
