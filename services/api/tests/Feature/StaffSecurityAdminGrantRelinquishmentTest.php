<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffSecurityAdminGrantRelinquishmentTest extends TestCase
{
    use RefreshDatabase;

    private const REVOKE = '/api/v1/staff/security/admin-grant/relinquish';
    private const APPROVALS = '/api/v1/staff/security/ads/assignment-approvals';

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Security Review Staff',
            'email' => Str::uuid().'@example.test',
            'password' => 'Safe!Staff2026',
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
        $staff = $this->staff();
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $staff->getKey(),
            'is_active' => true,
            'authorization_reference' => 'reviewed-independent-operator-authorization',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $staff;
    }

    private function organization(): AdvertiserOrganization
    {
        $org = AdvertiserOrganization::create(['name' => 'Organization '.Str::uuid()]);
        $org->forceFill(['is_active' => true])->save();

        return $org;
    }

    private function asStaff(StaffMember $staff, bool $stepUp = true, int $minutes = 5): string
    {
        // RefreshDatabase reuses numeric IDs across tests, whereas Redis
        // limiter counters may persist within the same CI job.
        RateLimiter::clear(sha1((string) $staff->getKey()));

        $token = $staff->createToken(
            'security-admin-relinquish',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;

        app('auth')->forgetGuards();
        $this->withToken($token);

        return $token;
    }

    private function requestAssignment(StaffMember $admin, StaffMember $target, AdvertiserOrganization $org): int
    {
        $this->asStaff($admin);

        return (int) $this->postJson(self::APPROVALS, [
            'target_staff_member_id' => $target->id,
            'advertiser_organization_id' => $org->id,
        ])->assertCreated()->json('request.id');
    }

    public function test_current_admin_can_relinquish_only_own_grant_with_atomic_audit(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->asStaff($admin);
        $response = $this->postJson(self::REVOKE)->assertOk()
            ->assertJsonPath('grant_status', 'relinquished')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $admin->id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $other->id, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $admin->id,
            'action' => 'staff.security_admin_grant.relinquished',
            'subject_type' => 'staff_member',
            'subject_id' => $admin->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_revoked_admin_is_immediately_denied_new_requests_reviews_and_replay(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->asStaff($admin);
        $this->postJson(self::REVOKE)->assertOk();
        $this->postJson(self::REVOKE)->assertForbidden();
        $this->getJson(self::APPROVALS)->assertForbidden();
        $this->postJson(self::APPROVALS, [
            'target_staff_member_id' => $this->staff('ads')->id,
            'advertiser_organization_id' => $this->organization()->id,
        ])->assertForbidden();
        $this->postJson(self::APPROVALS.'/9999/approve')->assertForbidden();

        $this->assertDatabaseCount('security_audit_events', 1);
        $this->asStaff($other);
        $this->getJson(self::APPROVALS)->assertOk();
    }

    public function test_mfa_step_up_is_required_and_rejected_request_has_no_audit_or_change(): void
    {
        $admin = $this->admin();
        $this->asStaff($admin, false);
        $this->postJson(self::REVOKE)->assertForbidden();

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $admin->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_requester_relinquishment_prevents_a_different_admin_from_approving_pending_work(): void
    {
        $requester = $this->admin();
        $approver = $this->admin();
        $target = $this->staff('ads');
        $org = $this->organization();
        $id = $this->requestAssignment($requester, $target, $org);

        $this->postJson(self::REVOKE)->assertOk();

        $this->asStaff($approver);
        $this->postJson(self::APPROVALS.'/'.$id.'/approve')->assertStatus(409);
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseHas('staff_ads_assignment_approvals', [
            'id' => $id, 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_approver_relinquishment_prevents_approval_without_affecting_requester(): void
    {
        $requester = $this->admin();
        $approver = $this->admin();
        $target = $this->staff('ads');
        $org = $this->organization();
        $id = $this->requestAssignment($requester, $target, $org);

        $this->asStaff($approver);
        $this->postJson(self::REVOKE)->assertOk();
        $this->postJson(self::APPROVALS.'/'.$id.'/approve')->assertForbidden();

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $requester->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_wrong_principal_roles_and_unprovisioned_staff_cannot_relinquish(): void
    {
        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Safe!Advertiser2026',
        ]);
        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->postJson(self::REVOKE)->assertForbidden();
        }

        foreach (['editor', 'support', 'finance', 'ads'] as $role) {
            $staff = $this->staff($role);
            $this->asStaff($staff);
            $this->postJson(self::REVOKE)->assertForbidden();
        }

        $this->postJson('/api/v1/staff/security/admin-grants')->assertNotFound();
        $this->assertDatabaseCount('security_audit_events', 0);
        $this->assertDatabaseCount('staff_security_admin_grants', 0);
    }

    public function test_inactive_unverified_and_removed_mfa_staff_fail_closed(): void
    {
        $staff = $this->admin();
        $this->asStaff($staff);

        $staff->forceFill(['mfa_enrolled_at' => null])->save();
        app('auth')->forgetGuards();
        $this->postJson(self::REVOKE)->assertForbidden();

        $staff->forceFill(['mfa_enrolled_at' => now(), 'email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->postJson(self::REVOKE)->assertForbidden();

        $staff->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->postJson(self::REVOKE)->assertForbidden();

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $staff->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_expired_and_revoked_staff_tokens_cannot_relinquish(): void
    {
        $staff = $this->admin();
        $this->asStaff($staff, true, -1);
        $this->postJson(self::REVOKE)->assertUnauthorized();

        $this->asStaff($staff);
        $staff->tokens()->delete();
        app('auth')->forgetGuards();
        $this->postJson(self::REVOKE)->assertUnauthorized();

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $staff->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_client_cannot_select_another_grant_or_reactivate_own_privileges(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->asStaff($admin);

        foreach ([
            'staff_member_id' => $other->id,
            'target_staff_member_id' => $other->id,
            'authorization_reference' => 'unreviewed',
            'is_active' => true,
            'role' => 'security_admin',
        ] as $field => $value) {
            $this->postJson(self::REVOKE, [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $admin->id, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $other->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
        $this->postJson('/api/v1/staff/security/admin-grant/reactivate')->assertNotFound();
    }
}
