<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffFinanceEmergencyRevocationTest extends TestCase
{
    use RefreshDatabase;

    private const REVOKE = '/api/v1/staff/security/finance/staff/';
    private const ACCESS = '/api/v1/staff/finance/access';

    protected function setUp(): void
    {
        parent::setUp();
        // Keep authorization and staff-session middleware enabled.
        // Request throttling is covered separately by the existing suite.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function staff(string $role): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Finance Security Test Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Staff!StrongPassword2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function admin(): StaffMember
    {
        $admin = $this->staff('support');
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $admin->id,
            'is_active' => true,
            'authorization_reference' => 'independent-security-review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin;
    }

    private function grant(StaffMember $staff): void
    {
        DB::table('staff_finance_entitlements')->insert([
            'staff_member_id' => $staff->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asStaff(StaffMember $staff, bool $stepUp = true): void
    {
        $token = $staff->createToken(
            'finance-emergency-revoke-test',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes(5),
        )->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token);
    }

    public function test_independent_admin_revokes_only_target_entitlement_and_audits_once(): void
    {
        $admin = $this->admin();
        $finance = $this->staff('finance');
        $other = $this->staff('finance');
        $this->grant($finance);
        $this->grant($other);

        $this->asStaff($finance);
        $this->getJson(self::ACCESS)->assertOk();

        $this->asStaff($admin);
        $response = $this->postJson(self::REVOKE.$finance->id.'/revoke')
            ->assertOk()->assertJsonPath('access', 'revoked')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $other->id, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $admin->id,
            'action' => 'staff.finance_access.revoked',
            'subject_type' => 'staff_member',
            'subject_id' => $finance->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);

        $this->asStaff($finance);
        $this->getJson(self::ACCESS)->assertForbidden();
        $this->asStaff($other);
        $this->getJson(self::ACCESS)->assertOk();
    }

    public function test_revocation_requires_current_admin_grant_and_mfa(): void
    {
        $admin = $this->admin();
        $ordinary = $this->staff('support');
        $finance = $this->staff('finance');
        $this->grant($finance);

        $this->asStaff($ordinary);
        $this->postJson(self::REVOKE.$finance->id.'/revoke')->assertForbidden();
        $this->asStaff($admin, false);
        $this->postJson(self::REVOKE.$finance->id.'/revoke')->assertForbidden();

        $this->asStaff($admin);
        DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $admin->id)->update(['is_active' => false]);
        $this->postJson(self::REVOKE.$finance->id.'/revoke')->assertForbidden();

        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_reader_and_advertiser_tokens_cannot_revoke_staff_finance_access(): void
    {
        $finance = $this->staff('finance');
        $this->grant($finance);
        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Unauthorized Finance Revocation',
            'email' => Str::uuid().'@example.test',
            'password' => 'Advertiser!Strong2026',
        ]);

        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)
                ->postJson(self::REVOKE.$finance->id.'/revoke')
                ->assertForbidden();
        }
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_replay_self_revoke_unknown_target_and_client_overrides_are_denied(): void
    {
        $admin = $this->admin();
        $finance = $this->staff('finance');
        $this->grant($finance);
        $this->asStaff($admin);

        $this->postJson(self::REVOKE.$admin->id.'/revoke')->assertForbidden();
        $this->postJson(self::REVOKE.'999999/revoke')->assertNotFound();
        foreach ([
            'staff_member_id' => $admin->id,
            'target_staff_member_id' => $admin->id,
            'is_active' => true,
            'role' => 'finance',
            'approved_by' => $admin->id,
            'authorization_reference' => 'forged',
        ] as $field => $value) {
            $this->postJson(self::REVOKE.$finance->id.'/revoke', [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->postJson(self::REVOKE.$finance->id.'/revoke')->assertOk();
        $this->postJson(self::REVOKE.$finance->id.'/revoke')->assertNotFound();
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_stale_entitlement_can_be_revoked_after_target_role_downgrade(): void
    {
        $admin = $this->admin();
        $finance = $this->staff('finance');
        $this->grant($finance);
        $finance->forceFill(['role' => 'editor', 'is_active' => false])->save();

        $this->asStaff($admin);
        $this->postJson(self::REVOKE.$finance->id.'/revoke')->assertOk();
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id, 'is_active' => false,
        ]);
    }

    public function test_stale_finance_session_cannot_survive_role_downgrade_or_emergency_revocation(): void
    {
        $admin = $this->admin();
        $finance = $this->staff('finance');
        $this->grant($finance);

        // Keep the exact same already-issued MFA bearer token across transitions.
        $token = $finance->createToken(
            'stale-finance-session',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;

        app('auth')->forgetGuards();
        $this->withToken($token);
        $this->getJson(self::ACCESS)->assertOk();

        // A persisted role change must be checked afresh, not trusted from
        // the session or an otherwise still-active finance entitlement.
        $finance->forceFill(['role' => 'editor'])->save();
        app('auth')->forgetGuards();
        $this->withToken($token);
        $this->getJson(self::ACCESS)->assertForbidden();
        $this->postJson(self::ACCESS.'/relinquish')->assertForbidden();
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);

        // Security administrators can revoke stale entitlements even after
        // a role downgrade; restoring the role must not restore the grant.
        $this->asStaff($admin);
        $this->postJson(self::REVOKE.$finance->id.'/revoke')->assertOk();
        $finance->forceFill(['role' => 'finance'])->save();

        app('auth')->forgetGuards();
        $this->withToken($token);
        $this->getJson(self::ACCESS)->assertForbidden();
        $this->postJson(self::ACCESS.'/relinquish')->assertForbidden();
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id, 'is_active' => false,
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_audit_failure_rolls_back_emergency_revocation(): void
    {
        $admin = $this->admin();
        $finance = $this->staff('finance');
        $this->grant($finance);
        DB::statement("ALTER TABLE security_audit_events ADD CONSTRAINT finance_revoke_audit_failure_test ".
            "CHECK (action <> 'staff.finance_access.revoked')");

        $this->asStaff($admin);
        $this->withoutExceptionHandling();
        try {
            $this->postJson(self::REVOKE.$finance->id.'/revoke');
            $this->fail('Audit failure must prevent finance entitlement revocation.');
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) $e->getCode());
        }
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }
}
