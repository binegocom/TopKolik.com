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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffFinanceAccessDualApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/staff/security/finance/access-requests';
    private const ACCESS = '/api/v1/staff/finance/access';

    private function staff(string $role): StaffMember
    {
        $s = StaffMember::create([
            'name' => 'Access Control Test Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Staff!StrongPassword2026',
            'role' => $role,
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
            'authorization_reference' => 'independently-reviewed-access-control',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $s;
    }

    private function asStaff(StaffMember $s, bool $stepUp = true, int $minutes = 5): void
    {
        RateLimiter::clear(sha1((string) $s->getKey()));
        $token = $s->createToken(
            'finance-entitlement-test',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token);
    }

    private function request(StaffMember $requester, StaffMember $target): int
    {
        $this->asStaff($requester);
        return (int) $this->postJson(self::API, ['target_staff_member_id' => $target->id])
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('request.id');
    }

    public function test_finance_role_is_insufficient_and_entitlement_defaults_to_inactive(): void
    {
        $finance = $this->staff('finance');
        $this->asStaff($finance);
        $this->getJson(self::ACCESS)->assertForbidden();
        $this->postJson(self::ACCESS.'/relinquish')->assertForbidden();

        DB::table('staff_finance_entitlements')->insert([
            'staff_member_id' => $finance->id,
            'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson(self::ACCESS)->assertForbidden();
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_two_distinct_admins_activate_only_eligible_finance_staff_with_audited_decision(): void
    {
        $first = $this->admin();
        $second = $this->admin('editor');
        $finance = $this->staff('finance');
        $another = $this->staff('finance');
        $id = $this->request($first, $finance);

        $this->assertDatabaseCount('staff_finance_entitlements', 0);
        $this->assertDatabaseHas('staff_finance_access_requests', [
            'id' => $id, 'status' => 'pending', 'requested_by_staff_member_id' => $first->id,
        ]);
        $this->asStaff($second);
        $response = $this->postJson(self::API.'/'.$id.'/approve')
            ->assertOk()->assertJsonPath('request.status', 'approved')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id,
            'is_active' => true,
            'last_approved_by_staff_id' => $second->id,
        ]);
        $this->assertDatabaseMissing('staff_finance_entitlements', ['staff_member_id' => $another->id]);
        $this->assertDatabaseHas('staff_finance_access_requests', [
            'id' => $id, 'status' => 'approved',
            'requested_by_staff_member_id' => $first->id,
            'reviewed_by_staff_member_id' => $second->id,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'action' => 'staff.finance_access.approved',
            'staff_member_id' => $second->id,
            'subject_type' => 'staff_member',
            'subject_id' => $finance->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_audit_events', 2);

        $this->asStaff($finance);
        $this->getJson(self::ACCESS)->assertOk()
            ->assertJsonPath('access', 'active')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('staff_member_id');
        $this->asStaff($another);
        $this->getJson(self::ACCESS)->assertForbidden();
    }

    public function test_requester_cannot_approve_self_and_replays_never_add_extra_events(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $finance = $this->staff('finance');
        $id = $this->request($first, $finance);
        $this->postJson(self::API.'/'.$id.'/approve')->assertForbidden();
        $this->postJson(self::API, ['target_staff_member_id' => $finance->id])->assertStatus(409);
        $this->assertDatabaseCount('staff_finance_entitlements', 0);

        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/approve')->assertOk();
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);
        $this->postJson(self::API.'/'.$id.'/reject')->assertStatus(409);
        $this->assertDatabaseCount('staff_finance_entitlements', 1);
        $this->assertDatabaseCount('security_audit_events', 2);

        $this->asStaff($first);
        $this->postJson(self::API, ['target_staff_member_id' => $finance->id])
            ->assertStatus(409);
    }

    public function test_denied_roles_wrong_principals_and_mfa_cannot_administer_finance(): void
    {
        $target = $this->staff('finance');
        foreach (['finance', 'support', 'ads', 'editor'] as $role) {
            $ordinary = $this->staff($role);
            $this->asStaff($ordinary);
            $this->getJson(self::API)->assertForbidden();
            $this->postJson(self::API, ['target_staff_member_id' => $target->id])
                ->assertForbidden();
        }

        $admin = $this->admin();
        $this->asStaff($admin, false);
        $this->postJson(self::API, ['target_staff_member_id' => $target->id])
            ->assertForbidden();
        $this->asStaff($admin, true, -1);
        $this->postJson(self::API, ['target_staff_member_id' => $target->id])
            ->assertUnauthorized();

        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Unauthorized Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Advertiser!Strong2026',
        ]);
        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)
                ->postJson(self::API, ['target_staff_member_id' => $target->id])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('staff_finance_access_requests', 0);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_reject_does_not_activate_and_expired_request_cannot_be_approved(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $finance = $this->staff('finance');
        $id = $this->request($first, $finance);

        $this->travel(2)->days();
        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);
        $this->postJson(self::API.'/'.$id.'/reject')->assertOk()
            ->assertJsonPath('request.status', 'rejected');
        $this->assertDatabaseCount('staff_finance_entitlements', 0);
        $this->assertDatabaseHas('staff_finance_access_requests', ['id' => $id, 'status' => 'rejected']);
        $this->travelBack();

        $next = $this->request($first, $finance);
        $this->assertNotSame($id, $next);
        $this->asStaff($second);
        $this->postJson(self::API.'/'.$next.'/approve')->assertOk();
        $this->assertDatabaseCount('security_audit_events', 4);
    }

    public function test_requester_revocation_and_ineligible_finance_target_block_approval(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $finance = $this->staff('finance');
        $id = $this->request($first, $finance);
        DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $first->id)->update(['is_active' => false]);

        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);
        DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $first->id)->update(['is_active' => true]);

        $finance->forceFill(['role' => 'support'])->save();
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);
        $finance->forceFill(['role' => 'finance', 'mfa_enrolled_at' => null])->save();
        $this->postJson(self::API.'/'.$id.'/approve')->assertStatus(409);

        $this->assertDatabaseCount('staff_finance_entitlements', 0);
        $this->assertDatabaseHas('staff_finance_access_requests', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_self_relinquishment_revokes_entitlement_and_new_two_person_review_can_restore(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $finance = $this->staff('finance');
        $id = $this->request($first, $finance);
        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/approve')->assertOk();

        $this->asStaff($finance);
        $response = $this->postJson(self::ACCESS.'/relinquish')
            ->assertOk()->assertJsonPath('access', 'relinquished');
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $finance->id,
            'action' => 'staff.finance_access.relinquished',
            'subject_type' => 'staff_member',
            'subject_id' => $finance->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->getJson(self::ACCESS)->assertForbidden();
        $this->postJson(self::ACCESS.'/relinquish')->assertForbidden();

        $newId = $this->request($first, $finance);
        $this->asStaff($second);
        $this->postJson(self::API.'/'.$newId.'/approve')->assertOk();
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $finance->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 5);
    }

    public function test_approval_queue_is_private_paginated_and_does_not_return_any_financial_data(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $target1 = $this->staff('finance');
        $target2 = $this->staff('finance');
        $id1 = $this->request($first, $target1);
        $id2 = $this->request($first, $target2);

        $this->asStaff($second);
        $this->getJson(self::API.'?limit=1')->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $id2)
            ->assertJsonPath('next_before_id', $id2)
            ->assertJsonMissingPath('requests.0.amount')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(self::API.'?limit=1&before_id='.$id2)->assertOk()
            ->assertJsonPath('requests.0.id', $id1)
            ->assertJsonPath('next_before_id', null);
        foreach ([
            'limit' => 51,
            'status' => 'approved',
            'target_staff_member_id' => $target1->id,
            'requested_by_staff_member_id' => $first->id,
        ] as $field => $value) {
            $this->getJson(self::API.'?'.http_build_query([$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_mass_assignment_and_nonfinance_targets_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->admin();
        $target = $this->staff('finance');
        $other = $this->staff('editor');
        $this->asStaff($admin);
        $this->postJson(self::API, ['target_staff_member_id' => $other->id])->assertNotFound();
        $this->postJson(self::API, ['target_staff_member_id' => $admin->id])->assertForbidden();
        foreach ([
            'status' => 'approved',
            'requested_by_staff_member_id' => $target->id,
            'reviewed_by_staff_member_id' => $admin->id,
            'is_active' => true,
            'role' => 'finance',
            'amount' => 1234,
        ] as $field => $value) {
            $this->postJson(self::API, ['target_staff_member_id' => $target->id, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('staff_finance_access_requests', 0);
        $this->assertDatabaseCount('staff_finance_entitlements', 0);
    }

    public function test_audit_failure_rolls_back_finance_access_activation(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $target = $this->staff('finance');
        $id = $this->request($first, $target);

        DB::statement("ALTER TABLE security_audit_events ADD CONSTRAINT finance_approval_audit_failure_test ".
            "CHECK (action <> 'staff.finance_access.approved')");

        $this->asStaff($second);
        $this->withoutExceptionHandling();
        try {
            $this->postJson(self::API.'/'.$id.'/approve');
            $this->fail('Audit failure must prevent finance privilege activation.');
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) $e->getCode());
        }

        $this->assertDatabaseCount('staff_finance_entitlements', 0);
        $this->assertDatabaseHas('staff_finance_access_requests', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_approved_finance_access_decision_cannot_be_mutated_or_deleted(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $target = $this->staff('finance');
        $id = $this->request($first, $target);
        $this->asStaff($second);
        $this->postJson(self::API.'/'.$id.'/approve')->assertOk();

        foreach ([
            fn () => DB::table('staff_finance_access_requests')
                ->where('id', $id)->update([
                    'status' => 'pending',
                    'reviewed_by_staff_member_id' => null,
                    'decided_at' => null,
                    'decided_request_id' => null,
                ]),
            fn () => DB::table('staff_finance_access_requests')->where('id', $id)->delete(),
            fn () => DB::statement('TRUNCATE staff_finance_access_requests'),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('Final finance access decisions must be immutable.');
            } catch (QueryException $e) {
                $this->assertTrue(
                    str_contains($e->getMessage(), 'immutable')
                    || str_contains($e->getMessage(), 'append-only'),
                );
            }
        }

        $this->assertDatabaseCount('staff_finance_access_requests', 1);
        $this->assertDatabaseCount('security_audit_events', 2);
    }
}
