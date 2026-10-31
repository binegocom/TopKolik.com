<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReaderPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('auth.reader_password_reset_url', 'https://accounts.example.test/reset-password');
    }

    private function credentials(User $user, string $token, string $password = 'New!StrongPassword2026'): array
    {
        return [
            'email' => $user->email,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }

    public function test_reset_request_is_generic_and_uses_hashed_single_use_token(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reader@example.test']);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'READER@example.test'])
            ->assertStatus(202)->assertJsonMissingPath('token')->json();
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])
            ->assertStatus(202)->json();
        $this->assertSame($known, $unknown);

        $rawToken = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$rawToken): bool {
            $rawToken = $notification->token;
            return true;
        });

        $this->assertNotNull($rawToken);
        $stored = DB::table('password_reset_tokens')->where('email', 'reader@example.test')->value('token');
        $this->assertNotSame($rawToken, $stored);
        $this->assertTrue(Hash::check($rawToken, $stored));
    }

    public function test_successful_reset_revokes_all_sanctum_tokens_and_database_sessions(): void
    {
        $user = User::factory()->create([
            'email' => 'reader@example.test',
            'password' => 'Old!Password2026',
        ]);
        $oldRememberToken = $user->getRememberToken();
        $first = $user->createToken('device-1', ['reader:read'])->plainTextToken;
        $user->createToken('device-2', ['reader:read']);
        DB::table('sessions')->insert([
            'id' => 'test-web-session-1',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'session',
            'last_activity' => time(),
        ]);
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/auth/password/reset', $this->credentials($user, $token))
            ->assertOk()->assertJsonMissingPath('access_token');

        $this->assertTrue(Hash::check('New!StrongPassword2026', $user->fresh()->password));
        $this->assertFalse(Hash::check('Old!Password2026', $user->fresh()->password));
        $this->assertNotSame($oldRememberToken, $user->fresh()->getRememberToken());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('sessions', ['id' => 'test-web-session-1']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_reset_token_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/api/v1/auth/password/reset', $this->credentials($user, $token))->assertOk();
        $this->postJson('/api/v1/auth/password/reset', $this->credentials($user, $token))
            ->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired password reset request.');
    }

    public function test_expired_and_invalid_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/auth/password/reset', $this->credentials($user, 'wrong'))
            ->assertUnprocessable();

        $this->travel(61)->minutes();
        $this->postJson('/api/v1/auth/password/reset', $this->credentials($user, $token))
            ->assertUnprocessable();
    }

    public function test_other_account_cannot_use_another_users_token(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::broker('users')->createToken($owner);

        $this->postJson('/api/v1/auth/password/reset', $this->credentials($other, $token))
            ->assertUnprocessable();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);
    }

    public function test_unrecognized_account_and_bad_token_have_same_reset_response(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.test']);
        $invalid = $this->postJson('/api/v1/auth/password/reset', $this->credentials($user, 'not-valid'))
            ->assertUnprocessable()->json();
        $unknown = $this->postJson('/api/v1/auth/password/reset', [
            ...$this->credentials($user, 'not-valid'), 'email' => 'unknown@example.test',
        ])->assertUnprocessable()->json();
        $this->assertSame($invalid, $unknown);
    }

    public function test_password_policy_and_confirmation_are_enforced(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            ...$this->credentials($user, $token), 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_three_forgot_requests_per_account_are_allowed_then_limited(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'reader@example.test']);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reader@example.test'])
                ->assertStatus(202);
        }
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reader@example.test'])
            ->assertStatus(429);
    }

    public function test_forgot_endpoint_fails_closed_without_trusted_frontend_url(): void
    {
        Notification::fake();
        User::factory()->create();
        config()->set('auth.reader_password_reset_url', null);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])
            ->assertStatus(503);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => User::firstOrFail()->email])
            ->assertStatus(503);
        Notification::assertNothingSent();
    }

    public function test_untrusted_url_or_insecure_external_http_url_is_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        foreach ([
            'http://evil.example.test/reset-password',
            'https://username@evil.example.test/reset-password',
            'https://example.test/reset-password?redirect=evil',
            'https://example.test/',
        ] as $url) {
            config()->set('auth.reader_password_reset_url', $url);
            $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
                ->assertStatus(503);
        }
        Notification::assertNothingSent();
    }

    public function test_current_login_credentials_change_after_reset(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.test', 'password' => 'Old!Password2026']);
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/auth/password/reset', $this->credentials($user, $token))->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Old!Password2026',
        ])->assertUnauthorized();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'New!StrongPassword2026',
        ])->assertOk();
        $login->assertJsonStructure(['access_token']);
    }
}
