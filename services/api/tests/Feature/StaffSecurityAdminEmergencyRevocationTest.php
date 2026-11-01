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

final class StaffSecurityAdminEmergencyRevocationTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVALS = '/api/v1/staff/security/ads/assignment-approvals';

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Authorized Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!Staff2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function administrator(string $role = 'support'): StaffMember
    {
        $staff = $this->staff($role);
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $staff->id,
            'is_active' => true,
            'authorization_reference' => 'independent-operational-security-authorization',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $staff;
    }

    private function organization(): AdvertiserOrganization
    {
        $org = AdvertiserOrganization::create(['name' => 'Advertiser '.Str::uuid()]);
        $org->forceFill(['is_active' => true])->save();

        return $org;
    }

    private function asStaff(StaffMember $staff, bool $stepUp = true, int $minutes = 5): void
    {
        // Each PostgreSQL test reuses staff integer IDs. Reset only the
        // corresponding test principal's Redis limiter counter.
        RateLimiter::clear(sha1((string) $staff->getKey()));
        $token = $staff->createToken(
            'emergency-admin-revoke',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;

        app('auth')->forgetGuards();
        $this->withToken($token);
    }

    private function url(StaffMember $staff): string
    {
        return '/api/v1/staff/security/admin-grants/'.$staff->id.'/revoke';
    }

    public function test_independent_security_admin_can_revoke_another_grant_with_audit_but_not_other_rights(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator('finance');
        $unrelated = $this->administrator('editor');
        $originalRole = $target->role;
        $this->asStaff($actor);

        $response = $this->postJson($this->url($target))
            ->assertOk()
            ->assertJsonPath('grant_status', 'revoked')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $target->id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $actor->id, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $unrelated->id, 'is_active' => true,
        ]);
        $this->assertSame($originalRole, $target->fresh()->role);
        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $actor->id,
            'action' => 'staff.security_admin_grant.revoked',
            'subject_type' => 'staff_member',
            'subject_id' => $target->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_emergency_revocation_immediately_closes_admin_only_endpoints_and_replay(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $this->asStaff($actor);

        $this->postJson($this->url($target))->assertOk();
        $this->postJson($this->url($target))->assertNotFound();
        $this->assertDatabaseCount('security_audit_events', 1);

        $this->asStaff($target);
        $this->getJson(self::APPROVALS)->assertForbidden();
        $this->postJson($this->url($actor))->assertForbidden();
        $this->postJson('/api/v1/staff/security/admin-grant/relinquish')->assertForbidden();
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $actor->id, 'is_active' => true,
        ]);
    }

    public function test_emergency_revocation_prevents_existing_requester_from_approving_work(): void
    {
        $requester = $this->administrator();
        $approver = $this->administrator();
        $target = $this->staff('ads');
        $org = $this->organization();

        $this->asStaff($requester);
        $id = (int) $this->postJson(self::APPROVALS, [
            'target_staff_member_id' => $target->id,
            'advertiser_organization_id' => $org->id,
        ])->assertCreated()->json('request.id');

        $this->asStaff($approver);
        $this->postJson($this->url($requester))->assertOk();
        $this->postJson(self::APPROVALS.'/'.$id.'/approve')->assertStatus(409);

        $this->assertDatabaseHas('staff_ads_assignment_approvals', [
            'id' => $id, 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_actor_cannot_use_other_revocation_route_to_revoke_self(): void
    {
        $actor = $this->administrator();
        $this->asStaff($actor);
        $this->postJson($this->url($actor))->assertForbidden();
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $actor->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_non_admin_wrong_role_and_wrong_principal_types_are_denied(): void
    {
        $target = $this->administrator();
        foreach (['editor', 'support', 'finance', 'ads'] as $role) {
            $other = $this->staff($role);
            $this->asStaff($other);
            $this->postJson($this->url($target))->assertForbidden();
        }

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
            $this->withToken($token)
                ->postJson($this->url($target))->assertForbidden();
        }

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $target->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_step_up_expiry_and_revoked_actor_grants_fail_closed(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $this->asStaff($actor, false);
        $this->postJson($this->url($target))->assertForbidden();

        $this->asStaff($actor, true, -1);
        $this->postJson($this->url($target))->assertUnauthorized();

        $this->asStaff($actor);
        DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $actor->id)
            ->update(['is_active' => false]);

        $this->postJson($this->url($target))->assertForbidden();
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $target->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_disabled_target_can_still_be_safely_revoked_and_unknown_is_not_disclosed(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $target->forceFill(['is_active' => false])->save();
        $this->asStaff($actor);

        $this->postJson($this->url($target))->assertOk();
        $this->postJson('/api/v1/staff/security/admin-grants/987654321/revoke')
            ->assertNotFound();
        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertFalse((bool) $target->fresh()->is_active);
    }

    public function test_client_cannot_inject_actor_role_grant_or_other_staff_selection(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $this->asStaff($actor);

        foreach ([
            'staff_member_id' => $actor->id,
            'target_staff_member_id' => $actor->id,
            'authorization_reference' => 'malicious-authorization',
            'is_active' => true,
            'role' => 'finance',
            'grant' => true,
        ] as $field => $value) {
            $this->postJson($this->url($target), [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->postJson('/api/v1/staff/security/admin-grants/'.$target->id.'/activate')
            ->assertNotFound();
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $target->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }
}
