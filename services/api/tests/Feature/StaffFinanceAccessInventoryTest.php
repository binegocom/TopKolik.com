<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffFinanceAccessInventoryTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE = '/api/v1/staff/security/finance/entitlements';
    private const HISTORY = '/api/v1/staff/security/finance/access-requests/history';
    private const REQUESTS = '/api/v1/staff/security/finance/access-requests';

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Inventory Security Staff',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!Inventory2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true, 'email_verified_at' => now(), 'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function admin(): StaffMember
    {
        $staff = $this->staff();
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $staff->id,
            'is_active' => true,
            'authorization_reference' => 'independently-reviewed-security-operator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $staff;
    }

    private function asStaff(StaffMember $staff, bool $stepUp = true, int $minutes = 5): void
    {
        RateLimiter::clear(sha1((string) $staff->id));
        $token = $staff->createToken(
            'finance-audit-inventory',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token);
    }

    private function decision(
        StaffMember $requester,
        StaffMember $reviewer,
        StaffMember $target,
        bool $approve = true,
    ): int {
        $this->asStaff($requester);
        $id = (int) $this->postJson(self::REQUESTS, [
            'target_staff_member_id' => $target->id,
        ])->assertCreated()->json('request.id');
        $this->asStaff($reviewer);
        $this->postJson(self::REQUESTS.'/'.$id.($approve ? '/approve' : '/reject'))
            ->assertOk();

        return $id;
    }

    public function test_active_inventory_returns_only_active_grants_without_personal_data(): void
    {
        $initiator = $this->admin();
        $approver = $this->admin();
        $first = $this->staff('finance');
        $second = $this->staff('finance');
        $inactive = $this->staff('finance');

        $this->decision($initiator, $approver, $first);
        $this->decision($initiator, $approver, $second);
        $this->decision($initiator, $approver, $inactive);
        $this->asStaff($inactive);
        $this->postJson('/api/v1/staff/finance/access/relinquish')->assertOk();

        $this->asStaff($approver);
        $response = $this->getJson(self::ACTIVE)->assertOk()
            ->assertJsonCount(2, 'entitlements')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('entitlements.0.staff_eligible', true)
            ->assertJsonPath('entitlements.1.staff_eligible', true)
            ->assertJsonPath('next_before_id', null)
            ->assertJsonMissingPath('entitlements.0.email')
            ->assertJsonMissingPath('entitlements.0.password')
            ->assertJsonMissingPath('entitlements.0.mfa_secret')
            ->assertJsonMissingPath('entitlements.0.authorization_reference');

        $body = $response->getContent();
        $this->assertStringNotContainsString($first->email, $body);
        $this->assertStringNotContainsString($second->email, $body);
        $this->assertStringNotContainsString($inactive->email, $body);
        $this->assertDatabaseCount('staff_finance_entitlements', 3);
        $this->assertDatabaseCount('staff_finance_access_requests', 3);
        $this->assertDatabaseCount('security_audit_events', 7);
    }

    public function test_active_inventory_flags_stale_entitlements_without_granting_finance_access(): void
    {
        $initiator = $this->admin();
        $approver = $this->admin();
        $finance = $this->staff('finance');
        $this->decision($initiator, $approver, $finance);

        $finance->forceFill(['role' => 'support'])->save();
        $this->asStaff($approver);
        $this->getJson(self::ACTIVE)->assertOk()
            ->assertJsonCount(1, 'entitlements')
            ->assertJsonPath('entitlements.0.staff_member_id', $finance->id)
            ->assertJsonPath('entitlements.0.staff_eligible', false);

        $this->asStaff($finance);
        $this->getJson('/api/v1/staff/finance/access')->assertForbidden();
    }

    public function test_terminal_history_excludes_pending_and_preserves_original_decision_actors(): void
    {
        $initiator = $this->admin();
        $approver = $this->admin();
        $approved = $this->staff('finance');
        $rejected = $this->staff('finance');
        $pending = $this->staff('finance');

        $approvedId = $this->decision($initiator, $approver, $approved);
        $rejectedId = $this->decision($initiator, $approver, $rejected, false);
        $this->asStaff($initiator);
        $this->postJson(self::REQUESTS, ['target_staff_member_id' => $pending->id])
            ->assertCreated();

        $this->asStaff($approver);
        $response = $this->getJson(self::HISTORY)->assertOk()
            ->assertJsonCount(2, 'decisions')
            ->assertJsonPath('decisions.0.id', $rejectedId)
            ->assertJsonPath('decisions.0.status', 'rejected')
            ->assertJsonPath('decisions.0.requested_by_staff_member_id', $initiator->id)
            ->assertJsonPath('decisions.0.reviewed_by_staff_member_id', $approver->id)
            ->assertJsonPath('decisions.1.id', $approvedId)
            ->assertJsonPath('decisions.1.status', 'approved')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('decisions.0.requested_request_id')
            ->assertJsonMissingPath('decisions.0.decided_request_id')
            ->assertJsonMissingPath('decisions.0.amount');

        $this->assertStringNotContainsString($pending->email, $response->getContent());
        $this->assertDatabaseCount('staff_finance_access_requests', 3);
    }

    public function test_history_status_filter_and_cursor_are_strict_and_stable(): void
    {
        $initiator = $this->admin();
        $approver = $this->admin();
        $one = $this->staff('finance');
        $two = $this->staff('finance');
        $three = $this->staff('finance');

        $firstId = $this->decision($initiator, $approver, $one);
        $middleId = $this->decision($initiator, $approver, $two, false);
        $lastId = $this->decision($initiator, $approver, $three);

        $this->asStaff($approver);
        $this->getJson(self::HISTORY.'?limit=2')->assertOk()
            ->assertJsonCount(2, 'decisions')
            ->assertJsonPath('decisions.0.id', $lastId)
            ->assertJsonPath('decisions.1.id', $middleId)
            ->assertJsonPath('next_before_id', $middleId);
        $this->getJson(self::HISTORY.'?limit=2&before_id='.$middleId)
            ->assertOk()->assertJsonCount(1, 'decisions')
            ->assertJsonPath('decisions.0.id', $firstId)
            ->assertJsonPath('next_before_id', null);
        $this->getJson(self::HISTORY.'?status=rejected')->assertOk()
            ->assertJsonCount(1, 'decisions')
            ->assertJsonPath('decisions.0.id', $middleId);
        $this->getJson(self::HISTORY.'?status=approved')->assertOk()
            ->assertJsonCount(2, 'decisions');
    }

    public function test_active_inventory_keyset_cursor_paginates_without_implicit_filters(): void
    {
        $initiator = $this->admin();
        $approver = $this->admin();
        $first = $this->staff('finance');
        $second = $this->staff('finance');
        $third = $this->staff('finance');
        $this->decision($initiator, $approver, $first);
        $this->decision($initiator, $approver, $second);
        $this->decision($initiator, $approver, $third);

        $this->asStaff($approver);
        $firstPage = $this->getJson(self::ACTIVE.'?limit=2')->assertOk()
            ->assertJsonCount(2, 'entitlements')
            ->json();
        $this->assertNotNull($firstPage['next_before_id']);
        $this->getJson(self::ACTIVE.'?limit=2&before_id='.$firstPage['next_before_id'])
            ->assertOk()->assertJsonCount(1, 'entitlements')
            ->assertJsonPath('next_before_id', null);
    }

    public function test_query_scope_expansion_is_rejected_on_both_read_only_endpoints(): void
    {
        $admin = $this->admin();
        $this->asStaff($admin);

        foreach ([
            ['limit' => 0],
            ['limit' => 51],
            ['before_id' => -1],
            ['staff_member_id' => 999],
            ['target_staff_member_id' => 999],
            ['requested_by_staff_member_id' => 999],
            ['reviewed_by_staff_member_id' => 999],
            ['request_id' => Str::uuid()->toString()],
            ['sort' => 'created_at'],
            ['status' => 'pending'],
        ] as $filter) {
            $key = array_key_first($filter);
            foreach ([self::ACTIVE, self::HISTORY] as $endpoint) {
                $this->getJson($endpoint.'?'.http_build_query($filter))
                    ->assertUnprocessable()->assertJsonValidationErrors($key);
            }
        }
    }

    public function test_only_independently_authorized_mfa_administrators_can_read(): void
    {
        foreach (['finance', 'support', 'ads', 'editor'] as $role) {
            $ordinary = $this->staff($role);
            $this->asStaff($ordinary);
            $this->getJson(self::ACTIVE)->assertForbidden();
            $this->getJson(self::HISTORY)->assertForbidden();
        }

        $admin = $this->admin();
        $this->asStaff($admin, false);
        $this->getJson(self::ACTIVE)->assertForbidden();
        $this->getJson(self::HISTORY)->assertForbidden();

        $this->asStaff($admin, true, -1);
        $this->getJson(self::ACTIVE)->assertUnauthorized();
        $this->getJson(self::HISTORY)->assertUnauthorized();

        $this->asStaff($admin);
        $this->getJson(self::ACTIVE)->assertOk();
        DB::table('staff_security_admin_grants')->where('staff_member_id', $admin->id)
            ->update(['is_active' => false]);
        $this->getJson(self::ACTIVE)->assertForbidden();
        $this->getJson(self::HISTORY)->assertForbidden();
    }

    public function test_reader_and_advertiser_tokens_cannot_read_staff_finance_metadata(): void
    {
        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'External business',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!External2026',
        ]);
        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token);
            $this->getJson(self::ACTIVE)->assertForbidden();
            $this->getJson(self::HISTORY)->assertForbidden();
        }
    }

    public function test_read_only_views_never_mutate_entitlements_decisions_or_audit(): void
    {
        $initiator = $this->admin();
        $approver = $this->admin();
        $finance = $this->staff('finance');
        $this->decision($initiator, $approver, $finance);

        $entitlement = DB::table('staff_finance_entitlements')
            ->where('staff_member_id', $finance->id)->first();
        $approval = DB::table('staff_finance_access_requests')->first();
        $auditCount = DB::table('security_audit_events')->count();

        $this->asStaff($approver);
        $this->getJson(self::ACTIVE)->assertOk();
        $this->getJson(self::HISTORY)->assertOk();
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'id' => $entitlement->id,
            'staff_member_id' => $finance->id,
            'is_active' => true,
            'updated_at' => $entitlement->updated_at,
        ]);
        $this->assertDatabaseHas('staff_finance_access_requests', [
            'id' => $approval->id,
            'status' => 'approved',
            'decided_at' => $approval->decided_at,
        ]);
        $this->assertSame($auditCount, DB::table('security_audit_events')->count());
    }
}
