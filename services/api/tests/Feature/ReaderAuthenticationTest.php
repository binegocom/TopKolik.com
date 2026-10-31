<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class ReaderAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_anonymous_and_sends_verification_without_token(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sport Reader',
            'email' => 'READER@example.test',
            'password' => 'Security!Password2026',
            'password_confirmation' => 'Security!Password2026',
        ]);
        $response->assertStatus(202)->assertJsonMissingPath('access_token');
        $user = User::where('email', 'reader@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Security!Password2026', $user->password));
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_duplicate_registration_does_not_issue_new_token_or_disclose_account(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'reader@example.test']);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Another User',
            'email' => 'reader@example.test',
            'password' => 'Security!Password2026',
            'password_confirmation' => 'Security!Password2026',
        ])->assertStatus(202);
        $this->assertDatabaseCount('users', 1);
        Notification::assertNothingSent();
    }

    public function test_login_requires_valid_credentials_and_verified_email(): void
    {
        User::factory()->unverified()->create([
            'email' => 'reader@example.test',
            'password' => 'Security!Password2026',
        ]);
        $this->postJson('/api/v1/auth/login', [
            'email' => 'reader@example.test', 'password' => 'Security!Password2026',
        ])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test', 'password' => 'WrongPassword!123',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_signed_link_verifies_email_but_tampered_link_fails(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->getJson($url.'&modified=1')->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->getJson($url)->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verified_reader_can_login_read_profile_and_revoke_token(): void
    {
        $user = User::factory()->create([
            'email' => 'reader@example.test',
            'password' => 'Security!Password2026',
        ]);
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'reader@example.test', 'password' => 'Security!Password2026',
        ])->assertOk()->assertJsonPath('user.id', $user->id)->json();
        $token = $login['access_token'];
        $this->assertNotEmpty($token);
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.email', $user->email);
        $this->withHeaders($headers)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        // HTTP feature tests reuse one Laravel application; a new HTTP request boots a fresh guard.
        app('auth')->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_verification_resend_has_generic_response_and_does_not_reveal_membership(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'reader@example.test']);
        $known = $this->postJson('/api/v1/auth/verification/resend', ['email' => 'reader@example.test'])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/verification/resend', ['email' => 'nobody@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_register_input_validation_rejects_weak_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test',
            'email' => 'reader@example.test',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertDatabaseCount('users', 0);
    }
}
