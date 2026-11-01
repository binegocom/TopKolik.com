<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserCampaign;
use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsAssignmentSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ASSIGNMENTS = '/api/v1/staff/ads/assignments';

    private function staff(string $role = 'ads'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Advertising Reviewer',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!Staff2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function organization(bool $active = true): AdvertiserOrganization
    {
        $org = AdvertiserOrganization::create(['name' => 'Private organization '.Str::uuid()]);
        $org->forceFill(['is_active' => $active])->save();

        return $org;
    }

    private function assign(StaffMember $staff, AdvertiserOrganization $org, bool $active = true): int
    {
        return (int) DB::table('staff_advertiser_assignments')->insertGetId([
            'staff_member_id' => $staff->id,
            'advertiser_organization_id' => $org->id,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asStaff(StaffMember $staff, bool $stepUp = false, int $minutes = 15): string
    {
        $token = $staff->createToken(
            'staff-ads-assignment',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token);

        return $token;
    }

    private function relinquishUrl(AdvertiserOrganization $org): string
    {
        return self::ASSIGNMENTS.'/'.$org->id.'/relinquish';
    }

    public function test_self_list_only_contains_active_assignments_to_active_organizations(): void
    {
        $staff = $this->staff();
        $other = $this->staff();
        $own1 = $this->organization();
        $own2 = $this->organization();
        $revoked = $this->organization();
        $disabled = $this->organization(false);
        $foreign = $this->organization();

        $id1 = $this->assign($staff, $own1);
        $id2 = $this->assign($staff, $own2);
        $this->assign($staff, $revoked, false);
        $this->assign($staff, $disabled);
        $this->assign($other, $foreign);

        $this->asStaff($staff);
        $response = $this->getJson(self::ASSIGNMENTS)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonCount(2, 'assignments')
            ->assertJsonPath('assignments.0.id', $id2)
            ->assertJsonPath('assignments.0.organization_id', $own2->id)
            ->assertJsonPath('assignments.1.id', $id1)
            ->assertJsonPath('assignments.1.organization_name', $own1->name)
            ->assertJsonPath('next_before_id', null)
            ->assertJsonMissingPath('assignments.0.staff_member_id');

        $serialized = $response->getContent();
        $this->assertStringNotContainsString($foreign->name, $serialized);
        $this->assertStringNotContainsString($revoked->name, $serialized);
        $this->assertStringNotContainsString($disabled->name, $serialized);
        $this->assertDatabaseCount('staff_advertiser_assignments', 5);
    }

    public function test_keyset_cursor_and_client_selected_identity_filters_are_rejected(): void
    {
        $staff = $this->staff();
        $this->assign($staff, $this->organization());
        $second = $this->assign($staff, $this->organization());
        $third = $this->assign($staff, $this->organization());

        $this->asStaff($staff);
        $this->getJson(self::ASSIGNMENTS.'?limit=2')->assertOk()
            ->assertJsonPath('assignments.0.id', $third)
            ->assertJsonPath('assignments.1.id', $second)
            ->assertJsonPath('next_before_id', $second);
        $this->getJson(self::ASSIGNMENTS.'?limit=2&before_id='.$second)
            ->assertOk()->assertJsonCount(1, 'assignments')
            ->assertJsonPath('next_before_id', null);

        foreach ([
            ['limit' => 0],
            ['limit' => 51],
            ['before_id' => -1],
            ['staff_member_id' => 999],
            ['advertiser_organization_id' => 999],
            ['include_inactive' => true],
            ['is_active' => false],
        ] as $filters) {
            $field = array_key_first($filters);
            $this->getJson(self::ASSIGNMENTS.'?'.http_build_query($filters))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_self_relinquish_requires_step_up_and_records_atomic_metadata_audit(): void
    {
        $staff = $this->staff();
        $org = $this->organization();
        $id = $this->assign($staff, $org);

        $this->asStaff($staff);
        $this->postJson($this->relinquishUrl($org))->assertForbidden();
        $this->assertDatabaseHas('staff_advertiser_assignments', ['id' => $id, 'is_active' => true]);

        $this->asStaff($staff, true);
        $response = $this->postJson($this->relinquishUrl($org))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('organization_id', $org->id)
            ->assertJsonPath('assignment_status', 'relinquished');

        $this->assertDatabaseHas('staff_advertiser_assignments', ['id' => $id, 'is_active' => false]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff->id,
            'action' => 'staff.advertiser_assignment.relinquished',
            'subject_type' => 'advertiser_organization',
            'subject_id' => $org->id,
            'request_id' => $response->headers->get('X-Request-ID'),
        ]);
        $this->getJson(self::ASSIGNMENTS)->assertOk()->assertJsonCount(0, 'assignments');
        $this->getJson('/api/v1/staff/ads/organizations/'.$org->id.'/campaigns')
            ->assertNotFound();
        $this->getJson('/api/v1/staff/security/audit-events')
            ->assertOk()->assertJsonPath('events.0.action', 'staff.advertiser_assignment.relinquished')
            ->assertJsonPath('events.0.subject_id', $org->id);
    }

    public function test_replayed_relinquish_is_not_found_and_does_not_duplicate_audit(): void
    {
        $staff = $this->staff();
        $org = $this->organization();
        $id = $this->assign($staff, $org);
        $this->asStaff($staff, true);

        $this->postJson($this->relinquishUrl($org))->assertOk();
        $this->postJson($this->relinquishUrl($org))->assertNotFound();
        $this->assertDatabaseCount('security_audit_events', 1);
        $this->assertDatabaseHas('staff_advertiser_assignments', ['id' => $id, 'is_active' => false]);
    }

    public function test_staff_can_only_relinquish_their_own_assignment_and_not_other_organizations(): void
    {
        $staff = $this->staff();
        $other = $this->staff();
        $own = $this->organization();
        $foreign = $this->organization();
        $this->assign($staff, $own);
        $foreignId = $this->assign($other, $foreign);

        $this->asStaff($staff, true);
        $this->postJson($this->relinquishUrl($foreign))->assertNotFound();
        $this->assertDatabaseHas('staff_advertiser_assignments', ['id' => $foreignId, 'is_active' => true]);
        $this->assertDatabaseCount('security_audit_events', 0);
        $this->postJson($this->relinquishUrl($own))->assertOk();
        $this->assertDatabaseHas('staff_advertiser_assignments', ['id' => $foreignId, 'is_active' => true]);
    }

    public function test_relinquishing_one_assignment_preserves_the_other_tenant_scope(): void
    {
        $staff = $this->staff();
        $one = $this->organization();
        $two = $this->organization();
        $this->assign($staff, $one);
        $otherId = $this->assign($staff, $two);
        $this->asStaff($staff, true);

        $this->postJson($this->relinquishUrl($one))->assertOk();
        $this->getJson(self::ASSIGNMENTS)->assertOk()->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $otherId);
        $this->getJson('/api/v1/staff/ads/organizations/'.$one->id.'/campaigns')->assertNotFound();
        $this->getJson('/api/v1/staff/ads/organizations/'.$two->id.'/campaigns')->assertOk();
        $this->assertDatabaseCount('security_audit_events', 1);
    }

    public function test_downgraded_role_disabled_staff_and_removed_mfa_fail_closed(): void
    {
        $staff = $this->staff();
        $org = $this->organization();
        $this->assign($staff, $org);
        $this->asStaff($staff, true);
        $this->getJson(self::ASSIGNMENTS)->assertOk();

        $staff->forceFill(['role' => 'finance'])->save();
        $this->getJson(self::ASSIGNMENTS)->assertForbidden();
        $this->postJson($this->relinquishUrl($org))->assertForbidden();

        $staff->forceFill(['role' => 'ads', 'mfa_enrolled_at' => null])->save();
        app('auth')->forgetGuards();
        $this->getJson(self::ASSIGNMENTS)->assertForbidden();

        $staff->forceFill(['mfa_enrolled_at' => now(), 'email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->getJson(self::ASSIGNMENTS)->assertForbidden();

        $staff->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->getJson(self::ASSIGNMENTS)->assertForbidden();
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_expired_or_revoked_token_cannot_relinquish_assignment(): void
    {
        $staff = $this->staff();
        $org = $this->organization();
        $this->assign($staff, $org);
        $this->asStaff($staff, true, -1);
        $this->postJson($this->relinquishUrl($org))->assertUnauthorized();

        $this->asStaff($staff, true);
        $staff->tokens()->delete();
        app('auth')->forgetGuards();
        $this->postJson($this->relinquishUrl($org))->assertUnauthorized();
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_reader_advertiser_or_other_staff_roles_cannot_access_assignment_api(): void
    {
        $org = $this->organization();
        $ads = $this->staff();
        $this->assign($ads, $org);
        foreach (['editor', 'support', 'finance'] as $role) {
            $otherStaff = $this->staff($role);
            $this->asStaff($otherStaff, true);
            $this->getJson(self::ASSIGNMENTS)->assertForbidden();
            $this->postJson($this->relinquishUrl($org))->assertForbidden();
        }

        $advertiser = AdvertiserAccount::create([
            'name' => 'Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!Advertiser2026',
        ]);
        $reader = User::factory()->create();
        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            $advertiser->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson(self::ASSIGNMENTS)->assertForbidden();
            $this->postJson($this->relinquishUrl($org))->assertForbidden();
        }
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_untrusted_mass_assignment_fields_are_rejected_with_no_state_change(): void
    {
        $staff = $this->staff();
        $org = $this->organization();
        $id = $this->assign($staff, $org);
        $this->asStaff($staff, true);

        foreach ([
            'staff_member_id' => 999,
            'advertiser_organization_id' => 999,
            'is_active' => true,
            'role' => 'admin',
            'grant' => true,
        ] as $key => $value) {
            $this->postJson($this->relinquishUrl($org), [$key => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseHas('staff_advertiser_assignments', ['id' => $id, 'is_active' => true]);
        $this->assertDatabaseCount('security_audit_events', 0);
        $this->postJson('/api/v1/staff/ads/assignments/'.$org->id.'/activate')->assertNotFound();
        $this->postJson('/api/v1/staff/ads/assignments/'.$org->id.'/grant')->assertNotFound();
    }
}
