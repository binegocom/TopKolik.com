<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserCampaign;
use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsAssignmentDualApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/staff/security/ads/assignment-approvals';

    private function staff(string $role): StaffMember
    {
        $s = StaffMember::create([
            'name' => 'Security Operator',
            'email' => Str::uuid().'@example.test',
            'role' => $role,
            'password' => 'Secure!Staff2026',
        ]);
        $s->forceFill([
            'is_active' => true, 'email_verified_at' => now(), 'mfa_enrolled_at' => now(),
        ])->save();

        return $s;
    }

    private function admin(string $role = 'support'): StaffMember
    {
        $s = $this->staff($role);
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $s->id,
            'is_active' => true,
            'authorization_reference' => 'approved-security-operator-process',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $s;
    }

    private function org(bool $active = true): AdvertiserOrganization
    {
        $o = AdvertiserOrganization::create(['name' => 'Advertisement '.Str::uuid()]);
        $o->forceFill(['is_active' => $active])->save();

        return $o;
    }

    private function asStaff(StaffMember $s, bool $stepUp = true, int $minutes = 5): void
    {
        // Isolated PostgreSQL tests reuse numeric IDs between RefreshDatabase
        // cases while Redis survives the suite. Prevent other test cases from
        // exhausting this case's *real* per-principal route throttle.
        \Illuminate\Support\Facades\RateLimiter::clear(sha1((string) $s->getKey()));

        $token = $s->createToken(
            'security-administrator',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        );
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken);
    }

    private function requestPayload(StaffMember $target, AdvertiserOrganization $org): array
    {
        return [
            'target_staff_member_id' => $target->id,
            'advertiser_organization_id' => $org->id,
        ];
    }

    private function createRequest(
        StaffMember $requester,
        StaffMember $target,
        AdvertiserOrganization $org,
    ): int {
        $this->asStaff($requester);

        return (int) $this->postJson(self::API, $this->requestPayload($target, $org))
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('request.status', 'pending')->json('request.id');
    }

    public function test_security_admin_privileges_are_separate_inactive_by_default_and_never_inherited_from_staff_role(): void
    {
        $target = $this->staff('ads');
        $org = $this->org();
        foreach (['editor', 'support', 'finance', 'ads'] as $role) {
            $ordinary = $this->staff($role);
            $this->asStaff($ordinary);
            $this->getJson(self::API)->assertForbidden();
            $this->postJson(self::API, $this->requestPayload($target, $org))
                ->assertForbidden();
        }

        $admin = $this->staff('support');
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $admin->id,
            'is_active' => false,
            'authorization_reference' => 'pending-review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->asStaff($admin);
        $this->getJson(self::API)->assertForbidden();
        $this->assertDatabaseCount('staff_ads_assignment_approvals', 0);
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
    }

    public function test_two_distinct_approved_administrators_grant_only_selected_ads_assignment_with_atomic_audit(): void
    {
        $requester = $this->admin('support');
        $approver = $this->admin('finance');
        $target = $this->staff('ads');
        $org = $this->org();
        $foreign = $this->org();
        $requestId = $this->createRequest($requester, $target, $org);

        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseHas('staff_ads_assignment_approvals', [
            'id' => $requestId,
            'status' => 'pending',
            'requested_by_staff_member_id' => $requester->id,
        ]);

        $this->asStaff($approver);
        $response = $this->postJson(self::API.'/'.$requestId.'/approve')
            ->assertOk()->assertJsonPath('request.status', 'approved')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'staff_member_id' => $target->id,
            'advertiser_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('staff_advertiser_assignments', [
            'staff_member_id' => $target->id, 'advertiser_organization_id' => $foreign->id,
        ]);
        $this->assertDatabaseHas('staff_ads_assignment_approvals', [
            'id' => $requestId,
            'status' => 'approved',
            'requested_by_staff_member_id' => $requester->id,
            'reviewed_by_staff_member_id' => $approver->id,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $approver->id,
            'action' => 'staff.advertiser_assignment.approved',
            'subject_type' => 'advertiser_organization',
            'subject_id' => $org->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_audit_events', 2);

        $this->asStaff($target, false);
        $this->getJson('/api/v1/staff/ads/organizations/'.$org->id.'/campaigns')->assertOk();
        $this->getJson('/api/v1/staff/ads/organizations/'.$foreign->id.'/campaigns')->assertNotFound();
    }

    public function test_requester_cannot_self_approve_and_only_one_approval_transition_is_allowed(): void
    {
        $requester = $this->admin();
        $approver = $this->admin();
        $target = $this->staff('ads');
        $org = $this->org();
        $id = $this->createRequest($requester, $target, $org);

        $this->postJson(self::API.'/'.$id.'/approve')->assertForbidden();
        $this->postJson(self::API.'/'.$id.'/reject')->assertForbidden();
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseCount('security_audit_events', 1);

        $this->asStaff($approver);
        $this->postJson(self::API.'/'.$id.'/approve')->assertOk();
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);
        $this->postJson(self::API.'/'.$id.'/reject')->assertStatus(409);
        $this->assertDatabaseCount('staff_advertiser_assignments', 1);
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_duplicate_pending_or_existing_active_assignment_never_grants_extra_access(): void
    {
        $first = $this->admin('support');
        $second = $this->admin('finance');
        $target = $this->staff('ads');
        $org = $this->org();
        $id = $this->createRequest($first, $target, $org);

        $this->postJson(self::API, $this->requestPayload($target, $org))->assertStatus(409);
        $this->assertDatabaseCount('staff_ads_assignment_approvals', 1);

        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/approve')->assertOk();

        $this->asStaff($first);
        $this->postJson(self::API, $this->requestPayload($target, $org))->assertStatus(409);
        $this->assertDatabaseCount('staff_ads_assignment_approvals', 1);
        $this->assertDatabaseCount('staff_advertiser_assignments', 1);
    }

    public function test_distinct_security_admin_can_reject_without_creating_grant_and_new_request_can_follow(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $target = $this->staff('ads');
        $org = $this->org();
        $id = $this->createRequest($first, $target, $org);

        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/reject')->assertOk()
            ->assertJsonPath('request.status', 'rejected');
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $second->id,
            'action' => 'staff.advertiser_assignment.rejected',
        ]);

        $newId = $this->createRequest($first, $target, $org);
        $this->assertNotSame($id, $newId);
        $this->assertDatabaseCount('staff_ads_assignment_approvals', 2);
    }

    public function test_revoked_requester_authority_or_target_eligibility_blocks_approval(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $target = $this->staff('ads');
        $org = $this->org();
        $id = $this->createRequest($first, $target, $org);

        DB::table('staff_security_admin_grants')->where('staff_member_id', $first->id)
            ->update(['is_active' => false]);
        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);

        DB::table('staff_security_admin_grants')->where('staff_member_id', $first->id)
            ->update(['is_active' => true]);
        $target->forceFill(['mfa_enrolled_at' => null])->save();
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);
        $target->forceFill(['mfa_enrolled_at' => now()])->save();
        $org->forceFill(['is_active' => false])->save();
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);

        $this->assertDatabaseHas('staff_ads_assignment_approvals', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_independent_requester_grant_revocation_blocks_pending_ads_approval(): void
    {
        $requester = $this->admin('support');
        $approver = $this->admin('editor');
        $revoker = $this->admin('finance');
        $target = $this->staff('ads');
        $org = $this->org();
        $id = $this->createRequest($requester, $target, $org);

        $this->asStaff($revoker);
        $this->postJson('/api/v1/staff/security/admin-grants/'.$requester->id.'/revoke')
            ->assertOk()->assertJsonPath('grant_status', 'revoked');

        $this->asStaff($approver);
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);
        $this->assertDatabaseHas('staff_ads_assignment_approvals', [
            'id' => $id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('staff_advertiser_assignments', [
            'staff_member_id' => $target->id,
            'advertiser_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $revoker->id,
            'action' => 'staff.security_admin_grant.revoked',
            'subject_type' => 'staff_member',
            'subject_id' => $requester->id,
        ]);
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_independent_approver_grant_revocation_blocks_decision_with_stale_mfa_token(): void
    {
        $requester = $this->admin('support');
        $approver = $this->admin('editor');
        $revoker = $this->admin('finance');
        $target = $this->staff('ads');
        $org = $this->org();
        $id = $this->createRequest($requester, $target, $org);

        $this->asStaff($revoker);
        $this->postJson('/api/v1/staff/security/admin-grants/'.$approver->id.'/revoke')
            ->assertOk()->assertJsonPath('grant_status', 'revoked');

        $this->asStaff($approver);
        $this->postJson(self::API.'/'.$id.'/approve')->assertForbidden();
        $this->postJson(self::API.'/'.$id.'/reject')->assertForbidden();
        $this->getJson(self::API)->assertForbidden();

        $this->assertDatabaseHas('staff_ads_assignment_approvals', [
            'id' => $id,
            'status' => 'pending',
            'reviewed_by_staff_member_id' => null,
        ]);
        $this->assertDatabaseMissing('staff_advertiser_assignments', [
            'staff_member_id' => $target->id,
            'advertiser_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $revoker->id,
            'action' => 'staff.security_admin_grant.revoked',
            'subject_type' => 'staff_member',
            'subject_id' => $approver->id,
        ]);
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_expired_request_requires_rejection_before_new_attempt(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $target = $this->staff('ads');
        $org = $this->org();
        $id = $this->createRequest($first, $target, $org);

        $this->travel(2)->days();
        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);
        $this->postJson(self::API.'/'.$id.'/reject')->assertOk();

        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseHas('staff_ads_assignment_approvals', ['id' => $id, 'status' => 'rejected']);
        $this->travelBack();
    }

    public function test_admin_queue_is_step_up_scoped_and_pagination_does_not_disclose_unapproved_fields(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $target1 = $this->staff('ads');
        $target2 = $this->staff('ads');
        $org = $this->org();
        $id1 = $this->createRequest($a, $target1, $org);
        $id2 = $this->createRequest($a, $target2, $org);
        $this->asStaff($b);

        $this->getJson(self::API.'?limit=1')->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $id2)
            ->assertJsonPath('next_before_id', $id2)
            ->assertJsonMissingPath('requests.0.authorization_reference')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(self::API.'?limit=1&before_id='.$id2)->assertOk()
            ->assertJsonPath('requests.0.id', $id1)
            ->assertJsonPath('next_before_id', null);

        foreach ([
            'status' => 'approved',
            'staff_member_id' => 99,
            'advertiser_organization_id' => 99,
            'limit' => 51,
        ] as $key => $value) {
            $this->getJson(self::API.'?'.http_build_query([$key => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }

        $this->asStaff($b, false);
        $this->getJson(self::API)->assertForbidden();
        $this->postJson(self::API.'/'.$id1.'/approve')->assertForbidden();
    }

    public function test_wrong_principals_and_client_field_overrides_fail_closed(): void
    {
        $admin = $this->admin();
        $target = $this->staff('ads');
        $org = $this->org();

        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Safe!AdvertiserPassword',
        ]);

        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson(self::API)->assertForbidden();
            $this->postJson(self::API, $this->requestPayload($target, $org))->assertForbidden();
        }

        $this->asStaff($admin);
        foreach ([
            'status' => 'approved',
            'requested_by_staff_member_id' => $target->id,
            'role' => 'security_admin',
            'approved_by' => $admin->id,
            'is_active' => true,
        ] as $key => $value) {
            $this->postJson(self::API, [...$this->requestPayload($target, $org), $key => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseCount('staff_ads_assignment_approvals', 0);
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
    }

    public function test_database_prevents_rewrite_reapproval_deletion_and_unapproved_role_provisioning(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $target = $this->staff('ads');
        $org = $this->org();
        $id = $this->createRequest($a, $target, $org);
        $this->asStaff($b);
        $this->postJson(self::API.'/'.$id.'/approve')->assertOk();

        foreach ([
            fn () => DB::table('staff_ads_assignment_approvals')
                ->where('id', $id)->update(['status' => 'pending', 'reviewed_by_staff_member_id' => null, 'decided_at' => null, 'decided_request_id' => null]),
            fn () => DB::table('staff_ads_assignment_approvals')->where('id', $id)->delete(),
            fn () => DB::statement('TRUNCATE staff_ads_assignment_approvals'),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('Approval record modified unexpectedly.');
            } catch (QueryException $e) {
                $this->assertTrue(
                    str_contains($e->getMessage(), 'cannot be deleted')
                    || str_contains($e->getMessage(), 'immutable'),
                    'Database must reject mutations to a decided approval.',
                );
            }
        }

        $this->assertDatabaseCount('staff_ads_assignment_approvals', 1);
        $this->postJson('/api/v1/staff/security/admin-grants', ['staff_member_id' => $target->id])
            ->assertNotFound();
        $this->assertDatabaseCount('staff_security_admin_grants', 2);
    }

    public function test_emergency_deactivated_finance_requester_cannot_create_or_approve_ads_assignment(): void
    {
        $requester = $this->admin('finance');
        $deactivator = $this->admin('support');
        $approver = $this->admin('editor');
        $target = $this->staff('ads');
        $org = $this->org();
        $oldToken = $requester->createToken(
            'before-emergency-deactivation',
            ['staff:session', 'staff:step-up'], now()->addMinutes(5),
        )->plainTextToken;

        $this->asStaff($deactivator);
        $this->postJson('/api/v1/staff/security/finance/staff/'.$requester->id.'/deactivate')
            ->assertOk()->assertJsonPath('staff_status', 'deactivated');

        app('auth')->forgetGuards();
        $this->withToken($oldToken);
        $this->postJson(self::API, $this->requestPayload($target, $org))
            ->assertUnauthorized();
        $this->asStaff($requester);
        $this->postJson(self::API, $this->requestPayload($target, $org))
            ->assertForbidden();
        $this->asStaff($approver);
        $this->getJson(self::API)->assertOk()->assertJsonCount(0, 'requests');

        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $requester->id, 'is_active' => false,
        ]);
        $this->assertDatabaseCount('staff_ads_assignment_approvals', 0);
        $this->assertDatabaseCount('staff_advertiser_assignments', 0);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $deactivator->id,
            'action' => 'staff.finance_access.staff_deactivated',
            'subject_type' => 'staff_member',
            'subject_id' => $requester->id,
        ]);
    }

    public function test_ineligible_target_and_unverified_admin_are_never_granted(): void
    {
        $admin = $this->admin();
        $editor = $this->staff('editor');
        $org = $this->org();
        $this->asStaff($admin);
        $this->postJson(self::API, $this->requestPayload($editor, $org))->assertNotFound();
        $admin->forceFill(['email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->postJson(self::API, $this->requestPayload($this->staff('ads'), $org))
            ->assertForbidden();
        $this->assertDatabaseCount('staff_ads_assignment_approvals', 0);
    }
}
