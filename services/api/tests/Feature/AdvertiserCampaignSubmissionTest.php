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

final class AdvertiserCampaignSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): AdvertiserAccount
    {
        $account = AdvertiserAccount::create([
            'name' => 'Advertiser Campaign Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Safe!Advertiser2026',
        ]);
        $account->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        return $account;
    }

    private function organization(bool $active = true): AdvertiserOrganization
    {
        $org = AdvertiserOrganization::create(['name' => 'Organization '.Str::uuid()]);
        $org->forceFill(['is_active' => $active])->save();

        return $org;
    }

    private function member(AdvertiserAccount $account, AdvertiserOrganization $organization, string $role = 'owner'): void
    {
        DB::table('advertiser_memberships')->insert([
            'advertiser_organization_id' => $organization->id,
            'advertiser_account_id' => $account->id,
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function campaign(AdvertiserOrganization $org): AdvertiserCampaign
    {
        return AdvertiserCampaign::create([
            'advertiser_organization_id' => $org->id,
            'title' => 'Private advertiser draft',
            'body' => 'Confidential advertiser campaign copy for review submission.',
        ]);
    }

    private function url(AdvertiserOrganization $org, AdvertiserCampaign $campaign): string
    {
        return '/api/v1/advertiser/organizations/'.$org->id.'/campaigns/'.$campaign->id.'/submit';
    }

    private function access(AdvertiserAccount $account, array $abilities = ['advertiser:session'], int $minutes = 15): self
    {
        app('auth')->forgetGuards();
        $token = $account->createToken('advertiser-submit-test', $abilities, now()->addMinutes($minutes));

        return $this->withToken($token->plainTextToken);
    }

    private function staff(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Review Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!Staff2026',
            'role' => 'ads',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    public function test_owner_can_submit_draft_and_auditor_records_exact_transition(): void
    {
        $account = $this->advertiser();
        $org = $this->organization();
        $this->member($account, $org);
        $campaign = $this->campaign($org);

        $response = $this->access($account)->postJson(
            $this->url($org, $campaign), ['revision' => 1],
        )->assertOk()->assertJsonPath('campaign.status', 'in_review')
            ->assertJsonPath('campaign.revision', 2)
            ->assertJsonMissingPath('campaign.advertiser_organization_id')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame('in_review', $campaign->fresh()->status);
        $this->assertSame(2, $campaign->fresh()->revision);
        $this->assertDatabaseCount('advertiser_campaign_review_events', 1);
        $event = DB::table('advertiser_campaign_review_events')->first();
        $this->assertSame($org->id, $event->advertiser_organization_id);
        $this->assertSame($account->id, $event->advertiser_account_id);
        $this->assertSame($campaign->id, $event->advertiser_campaign_id);
        $this->assertSame('submitted_for_review', $event->action);
        $this->assertSame(1, (int) $event->from_revision);
        $this->assertSame(2, (int) $event->to_revision);
        $this->assertSame($response->headers->get('X-Request-ID'), $event->request_id);
        $this->assertSame([
            'id', 'advertiser_organization_id', 'advertiser_campaign_id',
            'advertiser_account_id', 'action', 'from_revision', 'to_revision',
            'request_id', 'created_at',
        ], array_keys((array) $event));
        $this->assertStringNotContainsString(
            $campaign->body,
            json_encode($event),
        );
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_editor_can_submit_and_staff_can_review_but_neither_side_can_publish(): void
    {
        $editor = $this->advertiser();
        $org = $this->organization();
        $this->member($editor, $org, 'editor');
        $campaign = $this->campaign($org);
        $staff = $this->staff();

        DB::table('staff_advertiser_assignments')->insert([
            'staff_member_id' => $staff->id,
            'advertiser_organization_id' => $org->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->access($editor)->postJson($this->url($org, $campaign), [
            'revision' => 1,
        ])->assertOk();

        $session = $staff->createToken('staff', ['staff:session'], now()->addMinutes(15));
        app('auth')->forgetGuards();
        $reviewUrl = '/api/v1/staff/ads/organizations/'.$org->id.'/campaigns';
        $this->withToken($session->plainTextToken)->getJson($reviewUrl)
            ->assertOk()->assertJsonCount(1, 'campaigns')
            ->assertJsonPath('campaigns.0.id', $campaign->id)
            ->assertJsonMissingPath('campaigns.0.title');
        $this->getJson($reviewUrl.'/'.$campaign->id)->assertForbidden();

        $stepped = $staff->createToken(
            'step-up', ['staff:session', 'staff:step-up'], now()->addMinutes(5),
        );
        app('auth')->forgetGuards();
        $this->withToken($stepped->plainTextToken)
            ->getJson($reviewUrl.'/'.$campaign->id)->assertOk()
            ->assertJsonPath('campaign.body', $campaign->body);
        $this->postJson($reviewUrl.'/'.$campaign->id.'/approve')->assertNotFound();

        $this->access($editor)->postJson(
            '/api/v1/advertiser/organizations/'.$org->id.'/campaigns/'.$campaign->id.'/publish',
        )->assertNotFound();
        $this->assertSame('in_review', $campaign->fresh()->status);
    }

    public function test_replay_and_stale_revision_are_conflicts_without_duplicate_audit(): void
    {
        $owner = $this->advertiser();
        $org = $this->organization();
        $this->member($owner, $org);
        $campaign = $this->campaign($org);
        $url = $this->url($org, $campaign);

        $this->access($owner)->postJson($url, ['revision' => 20])
            ->assertStatus(409);
        $this->assertSame(1, $campaign->fresh()->revision);
        $this->assertDatabaseCount('advertiser_campaign_review_events', 0);

        $this->postJson($url, ['revision' => 1])
            ->assertOk()->assertJsonPath('campaign.revision', 2);
        $this->postJson($url, ['revision' => 1])->assertStatus(409);
        $this->postJson($url, ['revision' => 2])->assertStatus(409);
        $this->patchJson(
            '/api/v1/advertiser/organizations/'.$org->id.'/campaigns/'.$campaign->id,
            ['revision' => 2, 'title' => 'Unapproved late edit'],
        )->assertForbidden();

        $this->assertSame(2, $campaign->fresh()->revision);
        $this->assertDatabaseCount('advertiser_campaign_review_events', 1);
    }

    public function test_viewer_cannot_submit_and_role_downgrade_revokes_owner_privileges(): void
    {
        $owner = $this->advertiser();
        $org = $this->organization();
        $this->member($owner, $org, 'viewer');
        $campaign = $this->campaign($org);

        $this->access($owner)->postJson(
            $this->url($org, $campaign), ['revision' => 1],
        )->assertForbidden();

        DB::table('advertiser_memberships')->where('advertiser_account_id', $owner->id)
            ->where('advertiser_organization_id', $org->id)
            ->update(['role' => 'owner']);
        $this->postJson($this->url($org, $campaign), ['revision' => 1])
            ->assertOk();

        $draft = $this->campaign($org);
        DB::table('advertiser_memberships')->where('advertiser_account_id', $owner->id)
            ->where('advertiser_organization_id', $org->id)
            ->update(['role' => 'viewer']);
        $this->postJson($this->url($org, $draft), ['revision' => 1])
            ->assertForbidden();

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('advertiser_campaign_review_events', 1);
    }

    public function test_own_organization_url_cannot_submit_a_foreign_campaign(): void
    {
        $owner = $this->advertiser();
        $own = $this->organization();
        $foreign = $this->organization();
        $this->member($owner, $own);
        $this->member($this->advertiser(), $foreign);
        $ownCampaign = $this->campaign($own);
        $foreignCampaign = $this->campaign($foreign);

        $this->access($owner)->postJson(
            $this->url($foreign, $foreignCampaign), ['revision' => 1],
        )->assertNotFound();
        $this->postJson($this->url($own, $foreignCampaign), ['revision' => 1])
            ->assertNotFound();
        $this->postJson($this->url($own, $ownCampaign), ['revision' => 1])
            ->assertOk();

        $this->assertSame('draft', $foreignCampaign->fresh()->status);
        $this->assertSame('in_review', $ownCampaign->fresh()->status);
        $this->assertDatabaseCount('advertiser_campaign_review_events', 1);
    }

    public function test_membership_and_organization_deactivation_prevent_submissions(): void
    {
        $owner = $this->advertiser();
        $org = $this->organization();
        $this->member($owner, $org);
        $campaign = $this->campaign($org);
        $this->access($owner);

        DB::table('advertiser_memberships')->where('advertiser_account_id', $owner->id)
            ->update(['is_active' => false]);
        $this->postJson($this->url($org, $campaign), ['revision' => 1])->assertNotFound();

        DB::table('advertiser_memberships')->where('advertiser_account_id', $owner->id)
            ->update(['is_active' => true]);
        $org->forceFill(['is_active' => false])->save();
        $this->postJson($this->url($org, $campaign), ['revision' => 1])->assertNotFound();

        $this->assertDatabaseCount('advertiser_campaign_review_events', 0);
        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_submissions_require_active_verified_scoped_advertiser_session(): void
    {
        $owner = $this->advertiser();
        $org = $this->organization();
        $this->member($owner, $org);
        $campaign = $this->campaign($org);
        $url = $this->url($org, $campaign);

        $this->postJson($url, ['revision' => 1])->assertUnauthorized();
        $this->access($owner, ['reader:read'])
            ->postJson($url, ['revision' => 1])->assertForbidden();
        $this->access($owner, ['advertiser:session'], -1)
            ->postJson($url, ['revision' => 1])->assertUnauthorized();

        $this->access($owner);
        $owner->forceFill(['is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->postJson($url, ['revision' => 1])->assertForbidden();
        $owner->forceFill(['is_active' => true, 'email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->postJson($url, ['revision' => 1])->assertForbidden();

        $this->assertDatabaseCount('advertiser_campaign_review_events', 0);
        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_reader_and_staff_tokens_cannot_submit_advertiser_campaign(): void
    {
        $owner = $this->advertiser();
        $org = $this->organization();
        $this->member($owner, $org);
        $campaign = $this->campaign($org);
        $staff = $this->staff();
        $reader = User::factory()->create();

        foreach ([
            $reader->createToken('reader', ['advertiser:session'], now()->addMinutes(15))->plainTextToken,
            $staff->createToken('staff', ['advertiser:session'], now()->addMinutes(15))->plainTextToken,
        ] as $raw) {
            app('auth')->forgetGuards();
            $this->withToken($raw)->postJson($this->url($org, $campaign), [
                'revision' => 1,
            ])->assertForbidden();
        }
        $this->assertDatabaseCount('advertiser_campaign_review_events', 0);
    }

    public function test_submission_rejects_field_injection_and_missing_revision(): void
    {
        $owner = $this->advertiser();
        $org = $this->organization();
        $this->member($owner, $org);
        $campaign = $this->campaign($org);
        $url = $this->url($org, $campaign);
        $this->access($owner);

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('revision');
        foreach ([
            'title' => 'Unexpected overwritten title',
            'body' => 'Unexpected confidential body',
            'status' => 'approved',
            'advertiser_account_id' => 999,
            'advertiser_organization_id' => 999,
            'reviewer_id' => 999,
            'approved_by' => 999,
            'id' => 999,
        ] as $key => $value) {
            $this->postJson($url, ['revision' => 1, $key => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertSame('draft', $campaign->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->revision);
        $this->assertDatabaseCount('advertiser_campaign_review_events', 0);
    }

    public function test_audit_events_are_append_only_and_schema_constrained(): void
    {
        $owner = $this->advertiser();
        $org = $this->organization();
        $this->member($owner, $org);
        $campaign = $this->campaign($org);
        $this->access($owner)->postJson(
            $this->url($org, $campaign), ['revision' => 1],
        )->assertOk();
        $eventId = DB::table('advertiser_campaign_review_events')->value('id');

        foreach ([
            fn () => DB::table('advertiser_campaign_review_events')
                ->where('id', $eventId)->update(['action' => 'approved']),
            fn () => DB::table('advertiser_campaign_review_events')
                ->where('id', $eventId)->delete(),
            fn () => DB::statement('TRUNCATE advertiser_campaign_review_events'),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Review submission audit entry unexpectedly changed.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('advertiser_campaign_review_events', 1);
    }
}
