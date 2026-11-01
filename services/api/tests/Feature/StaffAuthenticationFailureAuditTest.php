<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use App\Services\StaffMfaService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class StaffAuthenticationFailureAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secure!StaffAuditPassword2026';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('staff-login-ip:127.0.0.1');
        RateLimiter::clear('staff-step-up-ip:127.0.0.1');
    }

    private function staff(bool $active = true): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Security Audit Operator',
            'email' => Str::uuid().'@example.test',
            'password' => self::PASSWORD,
            'role' => 'editor',
        ]);
        $staff->forceFill([
            'is_active' => $active,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function login(string $email, string $password = self::PASSWORD, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/staff/auth/login', [
            'email' => $email, 'password' => $password, ...$extra,
        ]);
    }

    public function test_unknown_and_known_wrong_password_have_identical_responses_without_plaintext(): void
    {
        $staff = $this->staff();
        $unknown = 'nobody-'.Str::uuid().'@example.test';
        $password = 'Wrong!NotAnActualPassword2026';
        $first = $this->login($unknown, $password)->assertUnauthorized();
        $second = $this->login($staff->email, $password)->assertUnauthorized();
        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('security_auth_failures', 2);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $events = DB::table('security_auth_failures')->orderBy('id')->get();
        $this->assertNull($events[0]->staff_member_id);
        $this->assertSame($staff->id, $events[1]->staff_member_id);
        $this->assertSame('staff.login.failed', $events[0]->action);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $events[0]->identity_fingerprint);
        $this->assertNotSame($events[0]->identity_fingerprint, $events[1]->identity_fingerprint);
        $this->assertSame($first->headers->get('X-Request-ID'), $events[0]->request_id);
        $this->assertSame($second->headers->get('X-Request-ID'), $events[1]->request_id);
        $serialized = json_encode($events);
        foreach ([$unknown, $staff->email, $password, self::PASSWORD] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_normalization_groups_case_variations_without_exposing_an_email(): void
    {
        $staff = $this->staff();
        $this->login(strtoupper($staff->email), 'wrong')->assertUnauthorized();
        $this->login($staff->email, 'wrong')->assertUnauthorized();

        $fingerprints = DB::table('security_auth_failures')
            ->orderBy('id')->pluck('identity_fingerprint')->all();
        $this->assertSame($fingerprints[0], $fingerprints[1]);
        $this->assertNotSame(hash('sha256', $staff->email), $fingerprints[0]);
    }

    public function test_mfa_failure_and_replayed_recovery_are_recorded_without_consuming_a_second_code(): void
    {
        $staff = $this->staff();
        $codes = app(StaffMfaService::class)->issueRecoveryCodes($staff);
        $first = $codes[0];

        $this->login($staff->email, self::PASSWORD, [
            'recovery_code' => str_repeat('A', 32),
        ])->assertUnauthorized();
        $this->assertDatabaseCount('staff_recovery_codes', 10);

        $this->login($staff->email, self::PASSWORD, [
            'recovery_code' => $first,
        ])->assertOk()->assertJsonStructure(['access_token']);
        $this->assertDatabaseCount('staff_recovery_codes', 9);

        $this->login($staff->email, self::PASSWORD, [
            'recovery_code' => $first,
        ])->assertUnauthorized();
        $this->assertDatabaseCount('security_auth_failures', 2);
        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertStringNotContainsString($first, json_encode(DB::table('security_auth_failures')->get()));
    }

    public function test_inactive_staff_failed_login_is_recorded_without_unlocking_account(): void
    {
        $staff = $this->staff(false);
        $this->login($staff->email)->assertUnauthorized();
        $this->assertDatabaseHas('security_auth_failures', [
            'staff_member_id' => $staff->id,
            'action' => 'staff.login.failed',
        ]);
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_step_up_creates_auth_failure_without_revoking_session_or_recording_factor(): void
    {
        $staff = $this->staff();
        $session = $staff->createToken('staff-session', ['staff:session'], now()->addMinutes(15))->plainTextToken;
        $factor = str_repeat('A', 32);

        $response = $this->withToken($session)->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => $factor,
        ])->assertUnauthorized();

        $this->assertDatabaseHas('security_auth_failures', [
            'action' => 'staff.step_up.failed',
            'staff_member_id' => $staff->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $row = DB::table('security_auth_failures')->first();
        $this->assertSame(
            hash_hmac('sha256', 'staff:'.$staff->email, (string) config('app.key')),
            $row->identity_fingerprint,
        );
        $this->assertStringNotContainsString($factor, json_encode($row));
        $this->assertStringNotContainsString($session, json_encode($row));
    }

    public function test_successful_step_up_does_not_create_failure_event(): void
    {
        $staff = $this->staff();
        $codes = app(StaffMfaService::class)->issueRecoveryCodes($staff);
        $session = $staff->createToken('staff-session', ['staff:session'], now()->addMinutes(15))->plainTextToken;
        $this->withToken($session)->postJson('/api/v1/staff/auth/step-up', [
            'recovery_code' => $codes[0],
        ])->assertOk()->assertJsonStructure(['access_token']);
        $this->assertDatabaseCount('security_auth_failures', 0);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff->id,
            'action' => 'staff.session.stepped_up',
        ]);
    }

    public function test_auth_failure_records_reject_updates_deletes_and_truncate(): void
    {
        $staff = $this->staff();
        $this->login($staff->email, 'wrong')->assertUnauthorized();
        $id = DB::table('security_auth_failures')->value('id');

        foreach ([
            fn () => DB::table('security_auth_failures')->where('id', $id)->update(['action' => 'staff.step_up.failed']),
            fn () => DB::table('security_auth_failures')->where('id', $id)->delete(),
            fn () => DB::statement('TRUNCATE security_auth_failures'),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('The authentication audit ledger was altered.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('security_auth_failures', 1);
    }

    public function test_invalid_audit_action_and_untrusted_request_id_fail_closed(): void
    {
        $staff = $this->staff();
        foreach ([
            ['staff.session.started', $staff->email, $staff, null],
            ['staff.step_up.failed', $staff->email, null, null],
            ['staff.login.failed', $staff->email, $staff, 'untrusted-request-id'],
        ] as [$action, $identity, $actor, $requestId]) {
            try {
                app(SecurityAuditRecorder::class)->recordFailure($action, $identity, $actor, $requestId);
                $this->fail('Unapproved authentication audit input was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('security_auth_failures', 0);
            }
        }
    }
}
