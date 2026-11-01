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

final class StaffAuthenticationThrottleAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('staff-login-ip:127.0.0.1');
        RateLimiter::clear('staff-step-up-ip:127.0.0.1');
        RateLimiter::clear('staff-step-up-user:nonstaff');
    }

    private function staff(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Throttling Audit Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!ThrottleAudit2026',
            'role' => 'editor',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    public function test_account_limit_denial_is_audited_once_without_exposing_the_identity(): void
    {
        $staff = $this->staff();
        $payload = [
            'email' => strtoupper($staff->email),
            'password' => 'Wrong!ThrottleAudit2026',
        ];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/staff/auth/login', $payload)->assertUnauthorized();
        }

        $denied = $this->postJson('/api/v1/staff/auth/login', $payload)
            ->assertStatus(429)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['message' => 'Too Many Attempts.']);

        $this->assertNotNull($denied->headers->get('Retry-After'));
        $this->assertDatabaseCount('security_auth_failures', 11);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $rows = DB::table('security_auth_failures')->orderBy('id')->get();
        $last = $rows->last();
        $this->assertSame('staff.login.failed', $last->action);
        $this->assertNull($last->staff_member_id);
        $this->assertSame($denied->headers->get('X-Request-ID'), $last->request_id);
        $this->assertSame($rows->first()->identity_fingerprint, $last->identity_fingerprint);
        $serialized = json_encode($rows);
        $this->assertStringNotContainsString($staff->email, $serialized);
        $this->assertStringNotContainsString($payload['password'], $serialized);
    }

    public function test_ip_limit_denial_is_audited_with_an_unknown_valid_email(): void
    {
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $this->postJson('/api/v1/staff/auth/login', [
                'email' => Str::uuid().'@example.test',
                'password' => 'Invalid!Password2026',
            ])->assertUnauthorized();
        }

        $email = Str::uuid().'@example.test';
        $denied = $this->postJson('/api/v1/staff/auth/login', [
            'email' => $email,
            'password' => 'Invalid!Password2026',
        ])->assertStatus(429);

        $this->assertDatabaseCount('security_auth_failures', 16);
        $row = DB::table('security_auth_failures')->orderByDesc('id')->first();
        $this->assertNull($row->staff_member_id);
        $this->assertSame($denied->headers->get('X-Request-ID'), $row->request_id);
        $this->assertSame(
            hash_hmac('sha256', 'staff:'.$email, (string) config('app.key')),
            $row->identity_fingerprint,
        );
        $this->assertStringNotContainsString($email, json_encode($row));
    }

    public function test_throttled_malformed_identifier_does_not_create_a_fake_identity(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/staff/auth/login', [
                'email' => ['unexpected' => 'array'],
                'password' => 'invalid',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => ['unexpected' => 'array'],
            'password' => 'invalid',
        ])->assertStatus(429);

        $this->assertDatabaseCount('security_auth_failures', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_step_up_throttle_records_only_the_authenticated_staff_principal(): void
    {
        $staff = $this->staff();
        $session = $staff->createToken(
            'staff-session', ['staff:session'], now()->addMinutes(15),
        )->plainTextToken;

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withToken($session)->postJson('/api/v1/staff/auth/step-up', [
                'recovery_code' => str_repeat('A', 32),
            ])->assertUnauthorized();
        }

        $denied = $this->withToken($session)->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => str_repeat('A', 32),
        ])->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseCount('security_auth_failures', 7);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $row = DB::table('security_auth_failures')->orderByDesc('id')->first();
        $this->assertSame('staff.step_up.failed', $row->action);
        $this->assertSame($staff->id, $row->staff_member_id);
        $this->assertSame($denied->headers->get('X-Request-ID'), $row->request_id);
        $this->assertStringNotContainsString($session, json_encode($row));
        $this->assertStringNotContainsString(str_repeat('A', 32), json_encode($row));
    }

    public function test_reader_id_collision_does_not_exhaust_staff_mfa_principal_quota(): void
    {
        $staff = $this->staff();
        $reader = User::factory()->create();

        // Separate tables allocate the same numeric ID in this isolated test.
        $this->assertSame((string) $staff->getKey(), (string) $reader->getKey());
        RateLimiter::clear('staff-step-up-user:staff:'.$staff->getKey());

        $readerToken = $reader->createToken('reader', ['reader:read'])->plainTextToken;
        $staffToken = $staff->createToken(
            'staff-session', ['staff:session'], now()->addMinutes(15),
        )->plainTextToken;

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withToken($readerToken)->postJson('/api/v1/staff/auth/step-up', [
                'recovery_code' => str_repeat('A', 32),
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('security_auth_failures', 0);

        // Only clear the shared IP dimension to isolate the principal key.
        // The exhausted non-staff quota must not throttle this staff session.
        RateLimiter::clear('staff-step-up-ip:127.0.0.1');
        app('auth')->forgetGuards();

        $this->withToken($staffToken)->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => str_repeat('A', 32),
        ])->assertUnauthorized();

        $this->assertDatabaseCount('security_auth_failures', 1);
        $this->assertSame(
            $staff->getKey(),
            DB::table('security_auth_failures')->sole()->staff_member_id,
        );
    }

    public function test_reader_session_cannot_create_staff_throttle_audit_records(): void
    {
        $reader = User::factory()->create();
        $token = $reader->createToken('reader', ['reader:read'])->plainTextToken;

        // The throttle is deliberately applied before the session guard:
        // a reader remains unauthorized and cannot become an audit actor,
        // even after its request rate is exhausted.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withToken($token)->postJson('/api/v1/staff/auth/step-up', [
                'recovery_code' => str_repeat('A', 32),
            ])->assertForbidden();
        }

        $this->withToken($token)->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => str_repeat('A', 32),
        ])->assertStatus(429);

        $this->assertDatabaseCount('security_auth_failures', 0);
    }
}
