<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSessionMiddlewareAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('staff-step-up-ip:127.0.0.1');
        RateLimiter::clear('staff-step-up-user:nonstaff');
    }

    private function staff(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Middleware Audit Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!MiddlewareAudit2026',
            'role' => 'editor',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        RateLimiter::clear('staff-step-up-user:staff:'.$staff->getKey());

        return $staff;
    }

    private function staffSession(StaffMember $staff, array $abilities = ['staff:session']): string
    {
        return $staff->createToken(
            'staff-session', $abilities, now()->addMinutes(15),
        )->plainTextToken;
    }

    public function test_deactivated_staff_step_up_is_denied_and_audited_without_secrets(): void
    {
        $staff = $this->staff();
        $token = $this->staffSession($staff);
        $factor = str_repeat('A', 32);
        $staff->forceFill(['is_active' => false])->save();

        $response = $this->withToken($token)->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => $factor,
        ])->assertForbidden();

        $this->assertDatabaseCount('security_auth_failures', 1);
        $this->assertDatabaseCount('security_audit_events', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $row = DB::table('security_auth_failures')->sole();
        $this->assertSame('staff.step_up.failed', $row->action);
        $this->assertSame($staff->getKey(), $row->staff_member_id);
        $this->assertSame($response->headers->get('X-Request-ID'), $row->request_id);
        $this->assertSame(
            hash_hmac('sha256', 'staff:'.$staff->email, (string) config('app.key')),
            $row->identity_fingerprint,
        );
        foreach ([$staff->email, $token, $factor] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($row));
        }

        // An ordinary staff-session denial must not be misclassified as MFA.
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertForbidden();
        $this->assertDatabaseCount('security_auth_failures', 1);
    }

    public function test_removed_mfa_enrollment_is_denied_and_audited(): void
    {
        $staff = $this->staff();
        $token = $this->staffSession($staff);
        $staff->forceFill(['mfa_enrolled_at' => null])->save();

        $response = $this->withToken($token)->postJson('/api/v1/staff/auth/step-up', [
            'code' => '000000',
        ])->assertForbidden();

        $this->assertDatabaseHas('security_auth_failures', [
            'action' => 'staff.step_up.failed',
            'staff_member_id' => $staff->getKey(),
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_auth_failures', 1);
    }

    public function test_valid_staff_identity_with_missing_session_ability_is_audited(): void
    {
        $staff = $this->staff();
        $token = $this->staffSession($staff, ['unrelated:read']);

        $response = $this->withToken($token)->postJson('/api/v1/staff/auth/step-up', [
            'code' => '000000',
        ])->assertForbidden();

        $this->assertDatabaseHas('security_auth_failures', [
            'action' => 'staff.step_up.failed',
            'staff_member_id' => $staff->getKey(),
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_auth_failures', 1);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_reader_with_colliding_numeric_id_cannot_create_staff_audit_event(): void
    {
        $staff = $this->staff();
        $reader = User::factory()->create(['id' => $staff->getKey()]);
        $token = $reader->createToken('reader', ['reader:read'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/staff/auth/step-up', [
            'code' => '000000',
        ])->assertForbidden();

        $this->assertDatabaseCount('security_auth_failures', 0);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_revoked_and_invalid_bearer_tokens_cannot_create_a_staff_audit_actor(): void
    {
        $staff = $this->staff();
        $token = $this->staffSession($staff);
        $staff->tokens()->delete();

        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/staff/auth/step-up', [
            'code' => '000000',
        ])->assertUnauthorized();

        app('auth')->forgetGuards();
        $this->withToken('invalid-bearer-token')->postJson('/api/v1/staff/auth/step-up', [
            'code' => '000000',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('security_auth_failures', 0);
        $this->assertDatabaseCount('security_audit_events', 0);
    }
}
