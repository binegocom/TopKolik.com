<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffInvitation;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\StaffMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class StaffMfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('staff.accept_url', 'https://staff.example.test/invitation');
        config()->set('mail.default', 'array');
        Mail::fake();
        RateLimiter::clear('staff-mfa-setup-ip:127.0.0.1');
        RateLimiter::clear('staff-mfa-confirm-ip:127.0.0.1');
        RateLimiter::clear('staff-login-ip:127.0.0.1');
    }

    private function acceptInvite(): array
    {
        $mail = app(\App\Services\StaffInvitationService::class)
            ->issue('editor@example.test', 'editor', 'operator');
        $url = null;
        Mail::assertSent(\App\Mail\StaffInvitationMail::class, function (\App\Mail\StaffInvitationMail $mail) use (&$url): bool {
            $url = $mail->acceptUrl;

            return true;
        });
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $params);

        $response = $this->postJson('/api/v1/staff/invitations/accept', [
            'invitation' => $mail->id,
            'token' => $params['token'],
            'name' => 'Verified Editor',
            'password' => 'Strong!StaffPassword2026',
            'password_confirmation' => 'Strong!StaffPassword2026',
        ])->assertStatus(202)->assertJsonMissingPath('access_token');

        return [StaffMember::query()->where('email', 'editor@example.test')->firstOrFail(),
            $response->json('mfa_setup_token')];
    }

    private function enroll(): array
    {
        [$staff, $grant] = $this->acceptInvite();
        $setup = $this->postJson('/api/v1/staff/mfa/setup', ['setup_token' => $grant])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $secret = $setup['secret'];
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $confirmed = $this->postJson('/api/v1/staff/mfa/confirm', [
            'setup_token' => $grant, 'code' => $code,
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        return [$staff->fresh(), $secret, $code, $confirmed['recovery_codes'], $grant];
    }

    public function test_accepted_invite_returns_only_short_lived_setup_grant_not_staff_session(): void
    {
        [$staff, $grant] = $this->acceptInvite();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $grant);
        $this->assertSame(hash('sha256', $grant), $staff->mfa_setup_token_hash);
        $this->assertFalse($staff->is_active);
        $this->assertNull($staff->mfa_enrolled_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->getJson('/api/v1/staff/auth/me')->assertUnauthorized();
    }

    public function test_totp_setup_encrypts_secrets_and_must_be_confirmed(): void
    {
        [$staff, $grant] = $this->acceptInvite();
        $setup = $this->postJson('/api/v1/staff/mfa/setup', ['setup_token' => $grant])->assertOk()->json();
        $this->assertStringContainsString('otpauth://totp/', $setup['provisioning_uri']);
        $this->assertStringContainsString('secret=', $setup['provisioning_uri']);
        $this->assertNull($staff->fresh()->mfa_enrolled_at);
        $raw = DB::table('staff_members')->where('id', $staff->id)->value('mfa_pending_secret');
        $this->assertNotSame($setup['secret'], $raw);
        $this->assertStringNotContainsString($setup['secret'], $raw);
        $this->assertSame($setup['secret'], $staff->fresh()->mfa_pending_secret);

        $this->postJson('/api/v1/staff/mfa/confirm', [
            'setup_token' => $grant, 'code' => '000000',
        ])->assertUnprocessable();
        $this->assertNull($staff->fresh()->mfa_enrolled_at);
    }

    public function test_confirm_consumes_setup_token_and_returns_hashed_single_use_recovery_codes(): void
    {
        [$staff, $secret, $otp, $codes, $grant] = $this->enroll();
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $codes[0]);
        $this->assertNotNull($staff->mfa_enrolled_at);
        $this->assertGreaterThan(1, $staff->mfa_last_step);
        $this->assertFalse($staff->is_active);
        $this->assertSame($secret, $staff->mfa_secret);
        $this->assertNull($staff->mfa_pending_secret);
        $this->assertNull($staff->mfa_setup_token_hash);
        $this->assertDatabaseCount('staff_recovery_codes', 10);
        $this->assertDatabaseMissing('staff_recovery_codes', ['code_hash' => $codes[0]]);
        $this->assertDatabaseHas('staff_recovery_codes', ['code_hash' => hash('sha256', $codes[0])]);

        $raw = DB::table('staff_members')->where('id', $staff->id)->value('mfa_secret');
        $this->assertNotSame($secret, $raw);
        $this->postJson('/api/v1/staff/mfa/setup', ['setup_token' => $grant])->assertUnprocessable();
        $this->postJson('/api/v1/staff/mfa/confirm', [
            'setup_token' => $grant, 'code' => $otp,
        ])->assertUnprocessable();
    }

    public function test_expired_and_forged_setup_tokens_do_not_issue_totp_secret(): void
    {
        [$staff, $grant] = $this->acceptInvite();
        $this->postJson('/api/v1/staff/mfa/setup', ['setup_token' => str_repeat('a', 64)])
            ->assertUnprocessable();
        $staff->forceFill(['mfa_setup_expires_at' => now()->subMinute()])->save();
        $this->postJson('/api/v1/staff/mfa/setup', ['setup_token' => $grant])->assertUnprocessable();
        $this->assertNull($staff->fresh()->mfa_secret);
    }

    public function test_staff_must_complete_mfa_before_operator_activation(): void
    {
        [$staff, $grant] = $this->acceptInvite();
        $this->assertSame(1, Artisan::call('staff:activate', [
            'email' => $staff->email, '--operator' => 'trusted-operator',
        ]));
        $this->assertFalse($staff->fresh()->is_active);
        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email,
            'password' => 'Strong!StaffPassword2026',
            'code' => '123456',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_approved_staff_requires_second_factor_and_readers_cannot_access_staff_session(): void
    {
        [$staff, $secret, $enrollmentCode, $codes] = $this->enroll();
        $this->assertSame(0, Artisan::call('staff:activate', [
            'email' => $staff->email, '--operator' => 'trusted-operator',
        ]));
        $this->assertTrue($staff->fresh()->is_active);
        $this->assertSame('trusted-operator', $staff->fresh()->activated_by);

        $login = [
            'email' => $staff->email, 'password' => 'Strong!StaffPassword2026',
        ];
        $this->postJson('/api/v1/staff/auth/login', $login)->assertUnauthorized();
        $this->postJson('/api/v1/staff/auth/login', [...$login, 'code' => $enrollmentCode])
            ->assertUnauthorized();
        $this->postJson('/api/v1/staff/auth/login', [...$login, 'password' => 'wrong',
            'recovery_code' => $codes[0]])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $response = $this->postJson('/api/v1/staff/auth/login', [
            ...$login, 'recovery_code' => $codes[0],
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $token = $response->json('access_token');
        $this->assertNotEmpty($token);
        $this->assertSame(['staff:session'], $staff->fresh()->tokens()->latest('id')->first()->abilities);
        $this->assertDatabaseCount('staff_recovery_codes', 9);
        $this->postJson('/api/v1/staff/auth/login', [
            ...$login, 'recovery_code' => $codes[0],
        ])->assertUnauthorized();

        $this->withToken($token)
            ->getJson('/api/v1/staff/auth/me')
            ->assertOk()->assertJsonPath('staff.role', 'editor');
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_step_up_requires_fresh_factor_and_token_replay_is_denied(): void
    {
        [$staff, $secret, $enrollmentCode, $codes] = $this->enroll();
        Artisan::call('staff:activate', ['email' => $staff->email, '--operator' => 'operator']);
        $response = $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'Strong!StaffPassword2026',
            'recovery_code' => $codes[0],
        ])->assertOk();
        $this->withToken($response->json('access_token'))
            ->postJson('/api/v1/staff/auth/step-up', [])->assertUnauthorized();

        $stepped = $this->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => $codes[1],
        ])->assertOk();
        $token = $stepped->json('access_token');
        $this->assertNotEmpty($token);
        $this->assertEqualsCanonicalizing(['staff:session', 'staff:step-up'],
            $staff->tokens()->where('name', 'staff-step-up')->firstOrFail()->abilities);

        $this->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => $codes[1],
        ])->assertUnauthorized();
        $this->assertDatabaseCount('staff_recovery_codes', 8);

        $this->withToken($token)->postJson('/api/v1/staff/auth/logout')->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertUnauthorized();
    }

    public function test_reader_token_cannot_be_used_as_staff_session(): void
    {
        $reader = User::factory()->create();
        $token = $reader->createToken('reader', ['reader:read', 'reader:write'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => str_repeat('A', 32),
        ])->assertForbidden();
    }

    public function test_revoked_staff_members_cannot_reuse_previous_session(): void
    {
        [$staff, $secret, $otp, $codes] = $this->enroll();
        Artisan::call('staff:activate', ['email' => $staff->email, '--operator' => 'operator']);
        $token = $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'Strong!StaffPassword2026',
            'recovery_code' => $codes[0],
        ])->assertOk()->json('access_token');

        $staff->forceFill(['is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertForbidden();
    }
}
