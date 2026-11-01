<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\StaffResourceContext;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserCampaign;
use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdvertiserReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'ads', bool $active = true): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Ads Operations Staff',
            'email' => Str::uuid().'@example.test',
            'password' => 'Safe!StaffPassword2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => $active,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function org(bool $active = true): AdvertiserOrganization
    {
        $org = AdvertiserOrganization::create(['name' => 'Advertiser '.Str::uuid()]);
        $org->forceFill(['is_active' => $active])->save();

        return $org;
    }

    private function assign(StaffMember $staff, AdvertiserOrganization $org, bool $active = true): void
    {
        DB::table('staff_advertiser_assignments')->insert([
            'staff_member_id' => $staff->id,
            'advertiser_organization_id' => $org->id,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function campaign(AdvertiserOrganization $org, string $status = 'in_review'): AdvertiserCampaign
    {
        $campaign = AdvertiserCampaign::create([
            'advertiser_organization_id' => $org->id,
            'title' => 'Private advertiser campaign review',
            'body' => 'Confidential prepublication advertising creative for authorized reviewers only.',
        ]);
        $campaign->forceFill(['status' => $status])->save();

        return $campaign;
    }

    private function token(StaffMember $staff, bool $stepUp = false, int $minutes = 15): string
    {
        return $staff->createToken(
            'staff-ads-review',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken;
    }

    private function listUrl(AdvertiserOrganization $org): string
    {
        return '/api/v1/staff/ads/organizations/'.$org->id.'/campaigns';
    }

    public function test_assigned_ads_can_list_only_in_review_metadata_and_mfa_step_up_reads_body(): void
    {
        $staff = $this->staff();
        $org = $this->org();
        $this->assign($staff, $org);
        $review = $this->campaign($org);
        $this->campaign($org, 'draft');
        $url = $this->listUrl($org);

        $this->withToken($this->token($staff))
            ->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonCount(1, 'campaigns')
            ->assertJsonPath('campaigns.0.id', $review->id)
            ->assertJsonMissingPath('campaigns.0.title')
            ->assertJsonMissingPath('campaigns.0.body');

        $this->getJson($url.'/'.$review->id)->assertForbidden();

        app('auth')->forgetGuards();
        $this->withToken($this->token($staff, true))
            ->getJson($url.'/'.$review->id)->assertOk()
            ->assertJsonPath('campaign.title', $review->title)
            ->assertJsonPath('campaign.body', $review->body)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_foreign_organization_and_foreign_campaign_ids_are_not_discoverable(): void
    {
        $staff = $this->staff();
        $own = $this->org();
        $other = $this->org();
        $this->assign($staff, $own);
        $ownCampaign = $this->campaign($own);
        $foreign = $this->campaign($other);

        $this->withToken($this->token($staff, true))
            ->getJson($this->listUrl($other))->assertNotFound();
        $this->getJson($this->listUrl($other).'/'.$foreign->id)->assertNotFound();
        $this->getJson($this->listUrl($own).'/'.$foreign->id)->assertNotFound();
        $this->getJson($this->listUrl($own).'/'.$ownCampaign->id)->assertOk();
        $this->assertDatabaseCount('advertiser_campaigns', 2);
    }

    public function test_draft_is_never_exposed_even_with_valid_assignment_and_fresh_step_up(): void
    {
        $staff = $this->staff();
        $org = $this->org();
        $this->assign($staff, $org);
        $draft = $this->campaign($org, 'draft');

        $this->withToken($this->token($staff, true))
            ->getJson($this->listUrl($org))->assertOk()->assertJsonCount(0, 'campaigns');
        $this->getJson($this->listUrl($org).'/'.$draft->id)->assertNotFound();
    }

    public function test_inactive_assignment_and_deactivated_org_take_effect_immediately(): void
    {
        $staff = $this->staff();
        $org = $this->org();
        $this->assign($staff, $org);
        $campaign = $this->campaign($org);
        $this->withToken($this->token($staff, true));
        $this->getJson($this->listUrl($org).'/'.$campaign->id)->assertOk();

        DB::table('staff_advertiser_assignments')->where('staff_member_id', $staff->id)
            ->update(['is_active' => false]);
        $this->getJson($this->listUrl($org))->assertNotFound();
        $this->getJson($this->listUrl($org).'/'.$campaign->id)->assertNotFound();

        DB::table('staff_advertiser_assignments')->where('staff_member_id', $staff->id)
            ->update(['is_active' => true]);
        $org->forceFill(['is_active' => false])->save();
        $this->getJson($this->listUrl($org))->assertNotFound();
        $this->assertDatabaseCount('advertiser_campaigns', 1);
    }

    public function test_role_change_missing_mfa_and_unverified_staff_fail_closed(): void
    {
        $staff = $this->staff();
        $org = $this->org();
        $this->assign($staff, $org);
        $this->withToken($this->token($staff, true))
            ->getJson($this->listUrl($org))->assertOk();

        $staff->forceFill(['role' => 'finance'])->save();
        $this->getJson($this->listUrl($org))->assertNotFound();
        $staff->forceFill(['role' => 'ads', 'mfa_enrolled_at' => null])->save();
        $this->getJson($this->listUrl($org))->assertForbidden();

        $staff->forceFill(['mfa_enrolled_at' => now(), 'email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->getJson($this->listUrl($org))->assertForbidden();
        $staff->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->getJson($this->listUrl($org))->assertForbidden();
    }

    public function test_other_staff_roles_and_reader_advertiser_tokens_are_never_allowed(): void
    {
        $org = $this->org();
        $this->campaign($org);
        foreach (['editor', 'support', 'finance'] as $role) {
            $staff = $this->staff($role);
            $this->assign($staff, $org);
            app('auth')->forgetGuards();
            $this->withToken($this->token($staff, true))
                ->getJson($this->listUrl($org))->assertNotFound();
        }
        $reader = User::factory()->create();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!AdvertiserPassword',
        ]);
        foreach ([
            $reader->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(10))->plainTextToken,
            $advertiser->createToken('ads', ['staff:session', 'staff:step-up'], now()->addMinutes(10))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson($this->listUrl($org))->assertForbidden();
        }
    }

    public function test_absent_expired_and_revoked_staff_session_cannot_list(): void
    {
        $staff = $this->staff();
        $org = $this->org();
        $this->assign($staff, $org);
        $this->getJson($this->listUrl($org))->assertUnauthorized();
        $this->withToken($this->token($staff, false, -1))
            ->getJson($this->listUrl($org))->assertUnauthorized();

        $valid = $this->token($staff);
        app('auth')->forgetGuards();
        $this->withToken($valid)->getJson($this->listUrl($org))->assertOk();
        $staff->tokens()->delete();
        app('auth')->forgetGuards();
        $this->getJson($this->listUrl($org))->assertUnauthorized();
    }

    public function test_cursor_validation_and_client_selected_org_or_staff_is_rejected(): void
    {
        $staff = $this->staff();
        $org = $this->org();
        $this->assign($staff, $org);
        $first = $this->campaign($org);
        $second = $this->campaign($org);
        $third = $this->campaign($org);
        $this->withToken($this->token($staff));

        $this->getJson($this->listUrl($org).'?limit=2')->assertOk()
            ->assertJsonPath('campaigns.0.id', $third->id)
            ->assertJsonPath('campaigns.1.id', $second->id)
            ->assertJsonPath('next_before_id', $second->id);
        $this->getJson($this->listUrl($org).'?limit=2&before_id='.$second->id)->assertOk()
            ->assertJsonPath('campaigns.0.id', $first->id)
            ->assertJsonPath('next_before_id', null);

        foreach ([
            ['limit' => 51],
            ['limit' => 0],
            ['before_id' => -1],
            ['staff_member_id' => 999],
            ['advertiser_organization_id' => 999],
            ['status' => 'draft'],
        ] as $filters) {
            $key = array_key_first($filters);
            $this->getJson($this->listUrl($org).'?'.http_build_query($filters))
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
    }

    public function test_approval_publishing_finance_and_role_grants_remain_default_denied(): void
    {
        $staff = $this->staff();
        $org = $this->org();
        $this->assign($staff, $org);
        $campaign = $this->campaign($org);
        $access = $staff->createToken('privileged', ['staff:session', 'staff:step-up'], now()->addMinutes(5));
        $resource = new StaffResourceContext(
            $campaign->id, 'advertiser_campaign', 'in_review', advertiserId: $org->id,
        );
        foreach ([
            'advertising.campaign.approve',
            'advertising.campaign.publish',
            'advertising.campaign.delete',
            'finance.invoice.read',
            'finance.invoice.pay',
            'finance.reconciliation.approve',
            'staff.role.assign',
        ] as $action) {
            $this->assertFalse(Gate::forUser($staff)->allows('staff-resource', [
                $action, $resource, $access->accessToken,
            ]), $action);
        }

        $this->withToken($access->plainTextToken)
            ->postJson($this->listUrl($org).'/'.$campaign->id.'/approve')->assertNotFound();
        $this->postJson($this->listUrl($org).'/'.$campaign->id.'/publish')->assertNotFound();
        $this->assertSame('in_review', $campaign->fresh()->status);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_assignment_uniqueness_enforced_by_postgresql(): void
    {
        $staff = $this->staff();
        $org = $this->org();
        $this->assign($staff, $org);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->assign($staff, $org);
    }
}
