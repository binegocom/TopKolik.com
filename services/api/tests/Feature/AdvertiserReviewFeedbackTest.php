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

final class AdvertiserReviewFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function org(): AdvertiserOrganization
    {
        $org = AdvertiserOrganization::create(['name' => 'Ads '.Str::uuid()]);
        $org->forceFill(['is_active' => true])->save();

        return $org;
    }

    private function advertiser(): AdvertiserAccount
    {
        $a = AdvertiserAccount::create([
            'name' => 'Account Owner',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!Advertiser2026',
        ]);
        $a->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();

        return $a;
    }

    private function staff(string $role = 'ads'): StaffMember
    {
        $s = StaffMember::create([
            'name' => 'Reviewer',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!Reviewer2026',
            'role' => $role,
        ]);
        $s->forceFill([
            'is_active' => true, 'email_verified_at' => now(), 'mfa_enrolled_at' => now(),
        ])->save();

        return $s;
    }

    private function member(AdvertiserAccount $a, AdvertiserOrganization $o, string $role = 'owner'): void
    {
        DB::table('advertiser_memberships')->insert([
            'advertiser_account_id' => $a->id,
            'advertiser_organization_id' => $o->id,
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assign(StaffMember $s, AdvertiserOrganization $o): void
    {
        DB::table('staff_advertiser_assignments')->insert([
            'staff_member_id' => $s->id, 'advertiser_organization_id' => $o->id,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function campaign(AdvertiserOrganization $o): AdvertiserCampaign
    {
        $c = AdvertiserCampaign::create([
            'advertiser_organization_id' => $o->id,
            'title' => 'Private campaign title',
            'body' => 'Unpublished advertiser creative awaiting formal staff review.',
        ]);
        $c->forceFill(['status' => 'in_review', 'revision' => 2])->save();

        return $c;
    }

    private function staffToken(StaffMember $s, bool $stepUp = true, int $minutes = 5): void
    {
        app('auth')->forgetGuards();
        $this->withToken($s->createToken(
            'ads-review', $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes($minutes),
        )->plainTextToken);
    }

    private function advertiserToken(AdvertiserAccount $a): void
    {
        app('auth')->forgetGuards();
        $this->withToken($a->createToken(
            'advertiser', ['advertiser:session'], now()->addMinutes(15),
        )->plainTextToken);
    }

    private function reviewUrl(AdvertiserOrganization $o, AdvertiserCampaign $c): string
    {
        return '/api/v1/staff/ads/organizations/'.$o->id.'/campaigns/'.$c->id.'/request-changes';
    }

    private function feedbackUrl(AdvertiserOrganization $o, AdvertiserCampaign $c): string
    {
        return '/api/v1/advertiser/organizations/'.$o->id.'/campaigns/'.$c->id.'/review-feedback';
    }

    private function feedback(): string
    {
        return 'Please clarify the advertising claim and replace unsupported wording.';
    }

    public function test_assigned_ads_step_up_requests_changes_atomically_and_owner_reads_encrypted_feedback(): void
    {
        $o = $this->org();
        $s = $this->staff();
        $a = $this->advertiser();
        $this->assign($s, $o);
        $this->member($a, $o);
        $c = $this->campaign($o);

        $this->staffToken($s);
        $response = $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertOk()->assertJsonPath('campaign.status', 'draft')
            ->assertJsonPath('campaign.revision', 3)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(3, $c->fresh()->revision);
        $this->assertSame('draft', $c->fresh()->status);
        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 1);
        $raw = DB::table('advertiser_campaign_review_feedback')->first();
        $this->assertSame($s->id, $raw->staff_member_id);
        $this->assertSame($o->id, $raw->advertiser_organization_id);
        $this->assertSame($c->id, $raw->advertiser_campaign_id);
        $this->assertSame(2, (int) $raw->from_revision);
        $this->assertSame(3, (int) $raw->to_revision);
        $this->assertSame($response->headers->get('X-Request-ID'), $raw->request_id);
        $this->assertNotSame($this->feedback(), $raw->feedback);
        $this->assertStringNotContainsString($this->feedback(), json_encode($raw));

        $this->advertiserToken($a);
        $this->getJson($this->feedbackUrl($o, $c))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.feedback', $this->feedback())
            ->assertJsonPath('events.0.action', 'changes_requested')
            ->assertJsonMissingPath('events.0.staff_member_id')
            ->assertJsonPath('next_before_id', null);
    }

    public function test_request_changes_allows_advertiser_reedit_and_resubmit_with_next_revision(): void
    {
        $o = $this->org();
        $s = $this->staff();
        $a = $this->advertiser();
        $this->assign($s, $o);
        $this->member($a, $o, 'editor');
        $c = $this->campaign($o);

        $this->staffToken($s);
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertOk();

        $base = '/api/v1/advertiser/organizations/'.$o->id.'/campaigns/'.$c->id;
        $this->advertiserToken($a);
        $this->patchJson($base, [
            'revision' => 3, 'body' => 'Updated advertiser copy with the claim fully clarified for review.',
        ])->assertOk()->assertJsonPath('campaign.revision', 4);
        $this->postJson($base.'/submit', ['revision' => 4])->assertOk()
            ->assertJsonPath('campaign.status', 'in_review')
            ->assertJsonPath('campaign.revision', 5);
        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 1);
        $this->assertDatabaseCount('advertiser_campaign_review_events', 1);
    }

    public function test_ordinary_session_fails_step_up_and_forbidden_roles_never_mutate(): void
    {
        $o = $this->org();
        $s = $this->staff();
        $this->assign($s, $o);
        $c = $this->campaign($o);

        $this->staffToken($s, false);
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertForbidden();

        $s->forceFill(['role' => 'finance'])->save();
        $this->staffToken($s);
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertNotFound();
        $this->assertSame('in_review', $c->fresh()->status);
        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 0);
    }

    public function test_revoked_assignment_and_disabled_organization_fail_closed(): void
    {
        $o = $this->org();
        $s = $this->staff();
        $this->assign($s, $o);
        $c = $this->campaign($o);
        $this->staffToken($s);

        DB::table('staff_advertiser_assignments')->where('staff_member_id', $s->id)
            ->update(['is_active' => false]);
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertNotFound();

        DB::table('staff_advertiser_assignments')->where('staff_member_id', $s->id)
            ->update(['is_active' => true]);
        $o->forceFill(['is_active' => false])->save();
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertNotFound();

        $this->assertSame(2, $c->fresh()->revision);
        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 0);
    }

    public function test_foreign_campaign_id_is_not_discoverable_even_with_valid_assignment(): void
    {
        $o = $this->org();
        $foreign = $this->org();
        $s = $this->staff();
        $this->assign($s, $o);
        $owned = $this->campaign($o);
        $foreignCampaign = $this->campaign($foreign);
        $this->staffToken($s);

        $this->postJson($this->reviewUrl($o, $foreignCampaign), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertNotFound();
        $this->postJson($this->reviewUrl($foreign, $foreignCampaign), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertNotFound();
        $this->assertSame('in_review', $foreignCampaign->fresh()->status);
        $this->assertSame('in_review', $owned->fresh()->status);
        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 0);
    }

    public function test_stale_revision_duplicate_request_and_draft_state_do_not_write_audit(): void
    {
        $o = $this->org();
        $s = $this->staff();
        $this->assign($s, $o);
        $c = $this->campaign($o);
        $this->staffToken($s);
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 1, 'feedback' => $this->feedback(),
        ])->assertStatus(409);
        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 0);

        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertOk();
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertNotFound();
        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 1);
        $this->assertSame(3, $c->fresh()->revision);
    }

    public function test_feedback_access_is_scoped_to_owner_editor_and_not_viewer_or_foreign_tenant(): void
    {
        $o = $this->org();
        $foreign = $this->org();
        $staff = $this->staff();
        $owner = $this->advertiser();
        $viewer = $this->advertiser();
        $outsider = $this->advertiser();
        $this->assign($staff, $o);
        $this->member($owner, $o);
        $this->member($viewer, $o, 'viewer');
        $this->member($outsider, $foreign);
        $c = $this->campaign($o);

        $this->staffToken($staff);
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertOk();

        $this->advertiserToken($viewer);
        $this->getJson($this->feedbackUrl($o, $c))->assertForbidden();
        $this->advertiserToken($outsider);
        $this->getJson($this->feedbackUrl($o, $c))->assertNotFound();

        $this->advertiserToken($owner);
        $this->getJson($this->feedbackUrl($foreign, $c))->assertNotFound();
        $this->getJson($this->feedbackUrl($o, $c))->assertOk()->assertJsonCount(1, 'events');
    }

    public function test_tampered_request_fields_and_markup_are_rejected(): void
    {
        $o = $this->org();
        $staff = $this->staff();
        $this->assign($staff, $o);
        $c = $this->campaign($o);
        $this->staffToken($staff);

        foreach ([
            ['revision' => 2, 'feedback' => '<script>alert(1)</script>'],
            ['revision' => 2, 'feedback' => 'short'],
            ['revision' => 2, 'feedback' => $this->feedback(), 'status' => 'published'],
            ['revision' => 2, 'feedback' => $this->feedback(), 'advertiser_organization_id' => 99],
            ['revision' => 2, 'feedback' => $this->feedback(), 'staff_member_id' => 99],
            ['revision' => 2, 'feedback' => $this->feedback(), 'approved_by' => 99],
        ] as $payload) {
            $this->postJson($this->reviewUrl($o, $c), $payload)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 0);
        $this->assertSame('in_review', $c->fresh()->status);
    }

    public function test_review_feedback_events_are_append_only_and_encrypted(): void
    {
        $o = $this->org();
        $staff = $this->staff();
        $this->assign($staff, $o);
        $c = $this->campaign($o);
        $this->staffToken($staff);
        $this->postJson($this->reviewUrl($o, $c), [
            'revision' => 2, 'feedback' => $this->feedback(),
        ])->assertOk();
        $id = DB::table('advertiser_campaign_review_feedback')->value('id');

        foreach ([
            fn () => DB::table('advertiser_campaign_review_feedback')->where('id', $id)->update(['action' => 'approved']),
            fn () => DB::table('advertiser_campaign_review_feedback')->where('id', $id)->delete(),
            fn () => DB::statement('TRUNCATE advertiser_campaign_review_feedback'),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Immutable review feedback event changed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 1);
    }

    public function test_reader_staff_or_advertiser_token_cannot_cross_principal_boundaries(): void
    {
        $o = $this->org();
        $staff = $this->staff();
        $a = $this->advertiser();
        $this->assign($staff, $o);
        $this->member($a, $o);
        $c = $this->campaign($o);

        $tokens = [
            $a->createToken('advertiser', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
            User::factory()->create()->createToken('reader', ['staff:session', 'staff:step-up'], now()->addMinutes(5))->plainTextToken,
        ];
        foreach ($tokens as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->postJson($this->reviewUrl($o, $c), [
                'revision' => 2, 'feedback' => $this->feedback(),
            ])->assertForbidden();
        }

        app('auth')->forgetGuards();
        $this->withToken($staff->createToken('staff', ['advertiser:session'], now()->addMinutes(5))->plainTextToken)
            ->getJson($this->feedbackUrl($o, $c))->assertForbidden();

        $this->assertDatabaseCount('advertiser_campaign_review_feedback', 0);
    }
}
