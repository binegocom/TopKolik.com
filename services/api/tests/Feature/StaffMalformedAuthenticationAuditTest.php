<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffMalformedAuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('staff-login-ip:127.0.0.1');
        RateLimiter::clear('staff-step-up-ip:127.0.0.1');
    }

    private function staff(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Audit Validation Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!ValidationAudit2026',
            'role' => 'editor',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    public function test_malformed_login_factor_is_audited_without_issuing_a_token(): void
    {
        $staff = $this->staff();
        $response = $this->postJson('/api/v1/staff/auth/login', [
            'email' => strtoupper($staff->email),
            'password' => 'Secure!ValidationAudit2026',
            'code' => 'not-a-totp',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('security_auth_failures', 1);
        $row = DB::table('security_auth_failures')->first();
        $this->assertNull($row->staff_member_id);
        $this->assertSame('staff.login.failed', $row->action);
        $this->assertSame($response->headers->get('X-Request-ID'), $row->request_id);
        $this->assertSame(
            hash_hmac('sha256', 'staff:'.$staff->email, (string) config('app.key')),
            $row->identity_fingerprint,
        );
        $this->assertStringNotContainsString($staff->email, json_encode($row));
        $this->assertStringNotContainsString('Secure!ValidationAudit2026', json_encode($row));
        $this->assertStringNotContainsString('not-a-totp', json_encode($row));
    }

    public function test_missing_or_invalid_email_does_not_create_a_synthetic_identity(): void
    {
        $this->postJson('/api/v1/staff/auth/login', [
            'password' => 'password',
            'code' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => ['unexpected' => 'value'],
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('security_auth_failures', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_malformed_step_up_factor_is_audited_for_authenticated_staff_only(): void
    {
        $staff = $this->staff();
        $token = $staff->createToken(
            'staff-session', ['staff:session'], now()->addMinutes(15),
        )->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/v1/staff/auth/step-up', [
                'recovery_code' => 'invalid-factor',
            ])->assertUnprocessable()->assertJsonValidationErrors('recovery_code');

        $this->assertDatabaseHas('security_auth_failures', [
            'staff_member_id' => $staff->id,
            'action' => 'staff.step_up.failed',
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('security_audit_events', 0);
        $row = DB::table('security_auth_failures')->first();
        $this->assertStringNotContainsString($token, json_encode($row));
        $this->assertStringNotContainsString('invalid-factor', json_encode($row));
    }

    public function test_unauthenticated_malformed_step_up_does_not_create_an_actor_record(): void
    {
        $this->postJson('/api/v1/staff/auth/step-up', [
            'code' => 'invalid',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('security_auth_failures', 0);
    }
}
