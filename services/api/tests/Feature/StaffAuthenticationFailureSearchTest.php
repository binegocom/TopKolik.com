<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\SecurityAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAuthenticationFailureSearchTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/staff/security/authentication-failures';

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Authentication Audit Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!AuthenticationAudit2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function token(StaffMember $staff, bool $stepUp = true, int $minutes = 5): string
    {
        return $staff->createToken(
            'staff-failure-audit-test',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
    }

    private function failure(string $action, string $identity, ?StaffMember $staff = null, ?string $requestId = null): int
    {
        app(SecurityAuditRecorder::class)->recordFailure($action, $identity, $staff, $requestId);

        return (int) DB::table('security_auth_failures')->max('id');
    }

    public function test_only_own_failed_attempts_are_visible_without_identity_or_fingerprint(): void
    {
        $one = $this->staff();
        $other = $this->staff('editor');
        $requestId = (string) Str::uuid();
        $own = $this->failure('staff.login.failed', $one->email, $one, $requestId);
        $this->failure('staff.step_up.failed', $other->email, $other);
        $this->failure('staff.login.failed', 'unknown-'.Str::uuid().'@example.test');

        $this->withToken($this->token($one))->getJson(self::URI)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonCount(1, 'failures')
            ->assertJsonPath('failures.0.id', $own)
            ->assertJsonPath('failures.0.action', 'staff.login.failed')
            ->assertJsonPath('failures.0.request_id', $requestId)
            ->assertJsonPath('next_before_id', null)
            ->assertJsonMissingPath('failures.0.staff_member_id')
            ->assertJsonMissingPath('failures.0.identity_fingerprint')
            ->assertJsonMissingPath('failures.0.email');

        $this->assertDatabaseCount('security_auth_failures', 3);
    }

    public function test_filters_and_exclusive_cursor_are_bounded_and_staff_scoped(): void
    {
        $one = $this->staff();
        $other = $this->staff();
        $requestId = (string) Str::uuid();
        $first = $this->failure('staff.login.failed', $one->email, $one);
        $second = $this->failure('staff.step_up.failed', $one->email, $one, $requestId);
        $third = $this->failure('staff.login.failed', $one->email, $one);
        $fourth = $this->failure('staff.step_up.failed', $one->email, $one);
        $this->failure('staff.login.failed', $other->email, $other);

        $this->withToken($this->token($one));
        $this->getJson(self::URI.'?limit=2')->assertOk()
            ->assertJsonCount(2, 'failures')
            ->assertJsonPath('failures.0.id', $fourth)
            ->assertJsonPath('failures.1.id', $third)
            ->assertJsonPath('next_before_id', $third);

        $this->getJson(self::URI.'?limit=2&before_id='.$third)->assertOk()
            ->assertJsonCount(2, 'failures')
            ->assertJsonPath('failures.0.id', $second)
            ->assertJsonPath('failures.1.id', $first)
            ->assertJsonPath('next_before_id', null);

        $this->getJson(self::URI.'?action=staff.login.failed')
            ->assertOk()->assertJsonCount(2, 'failures');
        $this->getJson(self::URI.'?request_id='.$requestId)
            ->assertOk()->assertJsonCount(1, 'failures')
            ->assertJsonPath('failures.0.id', $second);
        $this->getJson(self::URI.'?request_id='.(string) Str::uuid())
            ->assertOk()->assertJsonCount(0, 'failures');
    }

    public function test_client_selected_identity_and_invalid_filters_are_rejected(): void
    {
        $staff = $this->staff();
        $other = $this->staff();
        $this->failure('staff.login.failed', $other->email, $other);
        $this->withToken($this->token($staff));

        foreach ([
            ['staff_member_id' => $other->id],
            ['actor_id' => $other->id],
            ['email' => $other->email],
            ['identity' => $other->email],
            ['identity_fingerprint' => str_repeat('a', 64)],
            ['limit' => 0],
            ['limit' => 51],
            ['before_id' => -1],
            ['action' => 'staff.session.started'],
            ['request_id' => 'not-a-uuid'],
        ] as $filters) {
            $field = array_key_first($filters);
            $this->getJson(self::URI.'?'.http_build_query($filters))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->getJson(self::URI)->assertOk()->assertJsonCount(0, 'failures');
        $this->assertDatabaseCount('security_auth_failures', 1);
    }

    public function test_unauthenticated_and_ordinary_sessions_cannot_read_failure_metadata(): void
    {
        $staff = $this->staff();
        $this->failure('staff.login.failed', $staff->email, $staff);
        $this->getJson(self::URI)->assertUnauthorized();
        $this->withToken($this->token($staff, false))
            ->getJson(self::URI)->assertForbidden();
    }

    public function test_revoked_expired_and_deactivated_sessions_fail_closed(): void
    {
        $staff = $this->staff();
        $this->failure('staff.login.failed', $staff->email, $staff);
        $this->withToken($this->token($staff, true, -1))
            ->getJson(self::URI)->assertUnauthorized();

        $valid = $this->token($staff);
        app('auth')->forgetGuards();
        $this->withToken($valid)->getJson(self::URI)->assertOk();

        $staff->tokens()->delete();
        app('auth')->forgetGuards();
        $this->withToken($valid)->getJson(self::URI)->assertUnauthorized();

        $active = $this->token($staff);
        $staff->forceFill(['is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->withToken($active)->getJson(self::URI)->assertForbidden();
    }

    public function test_missing_mfa_or_email_verification_removes_access_immediately(): void
    {
        $staff = $this->staff();
        $token = $this->token($staff);
        $staff->forceFill(['mfa_enrolled_at' => null])->save();
        $this->withToken($token)->getJson(self::URI)->assertForbidden();

        $staff->forceFill(['mfa_enrolled_at' => now(), 'email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson(self::URI)->assertForbidden();
    }

    public function test_reader_and_advertiser_tokens_cannot_access_staff_failures(): void
    {
        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Audit Boundary Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!Advertiser2026',
        ]);
        $advertiser->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();

        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson(self::URI)->assertForbidden();
        }
    }

    public function test_each_approved_staff_role_can_view_only_own_failures(): void
    {
        $members = [];
        foreach (['editor', 'support', 'ads', 'finance'] as $role) {
            $members[] = $this->staff($role);
        }

        foreach ($members as $member) {
            $this->failure('staff.login.failed', $member->email, $member);
        }

        foreach ($members as $member) {
            app('auth')->forgetGuards();
            $this->withToken($this->token($member))->getJson(self::URI)
                ->assertOk()->assertJsonCount(1, 'failures');
        }

        $this->assertDatabaseCount('security_auth_failures', 4);
    }
}
