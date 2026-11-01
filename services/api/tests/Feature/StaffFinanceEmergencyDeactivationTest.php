<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffFinanceEmergencyDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/v1/staff/security/finance/staff/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function staff(string $role): StaffMember
    {
        $member = StaffMember::create([
            'name' => 'Finance Offboarding Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!FinanceOffboarding2026',
            'role' => $role,
        ]);
        $member->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $member;
    }

    private function admin(): StaffMember
    {
        $member = $this->staff('support');
        $this->adminGrant($member);

        return $member;
    }

    private function adminGrant(StaffMember $member): void
    {
        DB::table('staff_security_admin_grants')->insert([
            'staff_member_id' => $member->id,
            'is_active' => true,
            'authorization_reference' => 'independently-approved-test-grant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function entitlement(StaffMember $member): void
    {
        DB::table('staff_finance_entitlements')->insert([
            'staff_member_id' => $member->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function token(StaffMember $member, bool $stepUp = true): string
    {
        return $member->createToken(
            'finance-emergency-offboarding-test',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes(5),
        )->plainTextToken;
    }

    private function asToken(string $token): void
    {
        app('auth')->forgetGuards();
        $this->withToken($token);
    }

    private function url(StaffMember $member): string
    {
        return self::ROUTE.$member->id.'/deactivate';
    }

    public function test_admin_atomically_deactivates_finance_staff_and_revokes_all_existing_access(): void
    {
        $admin = $this->admin();
        $target = $this->staff('finance');
        $other = $this->staff('finance');
        $this->entitlement($target);
        $this->entitlement($other);
        $this->adminGrant($target);
        $organization = AdvertiserOrganization::create(['name' => 'Offboarding '.Str::uuid()]);
        DB::table('staff_advertiser_assignments')->insert([
            'staff_member_id' => $target->id,
            'advertiser_organization_id' => $organization->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staleToken = $this->token($target);
        $otherToken = $this->token($other);
        $this->asToken($staleToken);
        $this->getJson('/api/v1/staff/finance/access')->assertOk();

        $this->asToken($this->token($admin));
        $response = $this->postJson($this->url($target))
            ->assertOk()->assertJsonPath('staff_status', 'deactivated')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame('finance', $target->fresh()->role);
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $target->id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $target->id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'staff_member_id' => $target->id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $other->id, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $admin->id,
            'action' => 'staff.finance_access.staff_deactivated',
            'subject_type' => 'staff_member',
            'subject_id' => $target->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_type', $target->getMorphClass())
            ->where('tokenable_id', $target->id)->count());

        $this->asToken($staleToken);
        $this->getJson('/api/v1/staff/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/staff/finance/access')->assertUnauthorized();
        $this->asToken($otherToken);
        $this->getJson('/api/v1/staff/finance/access')->assertOk();
    }

    public function test_only_independent_step_up_admin_can_deactivate(): void
    {
        $admin = $this->admin();
        $ordinary = $this->staff('support');
        $target = $this->staff('finance');
        $this->entitlement($target);

        $this->asToken($this->token($ordinary));
        $this->postJson($this->url($target))->assertForbidden();
        $this->asToken($this->token($admin, false));
        $this->postJson($this->url($target))->assertForbidden();
        $this->asToken($this->token($admin));
        DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $admin->id)->update(['is_active' => false]);
        $this->postJson($this->url($target))->assertForbidden();

        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $target->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_reader_and_advertiser_principals_cannot_deactivate_staff(): void
    {
        $target = $this->staff('finance');
        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Unauthorized Offboarding',
            'email' => Str::uuid().'@example.test',
            'password' => 'Advertiser!Strong2026',
        ]);

        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            $this->asToken($token);
            $this->postJson($this->url($target))->assertForbidden();
        }
        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_self_deactivation_replay_nonfinance_target_and_client_overrides_are_denied(): void
    {
        $admin = $this->admin();
        $target = $this->staff('finance');
        $nonfinance = $this->staff('editor');
        $this->asToken($this->token($admin));

        $this->postJson($this->url($admin))->assertForbidden();
        $this->postJson($this->url($nonfinance))->assertNotFound();
        $this->postJson(self::ROUTE.'999999/deactivate')->assertNotFound();
        foreach ([
            'staff_member_id' => $admin->id,
            'target_staff_member_id' => $admin->id,
            'role' => 'support',
            'is_active' => true,
            'entitlement' => true,
            'authorization_reference' => 'forged',
        ] as $field => $value) {
            $this->postJson($this->url($target), [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->postJson($this->url($target))->assertOk();
        $this->postJson($this->url($target))->assertNotFound();
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_pending_finance_approval_cannot_reactivate_deactivated_target(): void
    {
        $requester = $this->admin();
        $approver = $this->admin();
        $target = $this->staff('finance');
        $this->asToken($this->token($requester));
        $id = (int) $this->postJson('/api/v1/staff/security/finance/access-requests', [
            'target_staff_member_id' => $target->id,
        ])->assertCreated()->json('request.id');

        $this->asToken($this->token($approver));
        $this->postJson($this->url($target))->assertOk();
        $this->postJson('/api/v1/staff/security/finance/access-requests/'.$id.'/approve')
            ->assertStatus(409);
        $this->assertDatabaseHas('staff_finance_access_requests', [
            'id' => $id, 'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('staff_finance_entitlements', [
            'staff_member_id' => $target->id, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 2);
    }

    public function test_audit_failure_rolls_back_deactivation_entitlement_and_token_revocation(): void
    {
        $admin = $this->admin();
        $target = $this->staff('finance');
        $this->entitlement($target);
        $this->adminGrant($target);
        $targetToken = $this->token($target);
        DB::statement("ALTER TABLE security_audit_events ADD CONSTRAINT finance_offboarding_audit_failure_test ".
            "CHECK (action <> 'staff.finance_access.staff_deactivated')");

        $this->asToken($this->token($admin));
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->url($target));
            $this->fail('Audit failure must roll back every staff deactivation change.');
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) $e->getCode());
        }

        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseHas('staff_finance_entitlements', [
            'staff_member_id' => $target->id, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('staff_security_admin_grants', [
            'staff_member_id' => $target->id, 'is_active' => true,
        ]);
        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_type', $target->getMorphClass())
            ->where('tokenable_id', $target->id)->count());
        $this->assertDatabaseCount('security_audit_events', 0);

        $this->asToken($targetToken);
        $this->getJson('/api/v1/staff/finance/access')->assertOk();
    }
}
