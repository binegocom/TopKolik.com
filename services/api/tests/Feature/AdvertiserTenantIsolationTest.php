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

final class AdvertiserTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function account(bool $active = true, bool $verified = true): AdvertiserAccount
    {
        $account = AdvertiserAccount::create([
            'name' => 'Advertiser Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!Advertiser2026',
        ]);
        $account->forceFill([
            'is_active' => $active,
            'email_verified_at' => $verified ? now() : null,
        ])->save();

        return $account;
    }

    private function organization(bool $active = true): AdvertiserOrganization
    {
        $organization = AdvertiserOrganization::create(['name' => 'Test Organization '.Str::uuid()]);
        $organization->forceFill(['is_active' => $active])->save();

        return $organization;
    }

    private function member(AdvertiserAccount $account, AdvertiserOrganization $organization, string $role = 'owner', bool $active = true): void
    {
        DB::table('advertiser_memberships')->insert([
            'advertiser_account_id' => $account->id,
            'advertiser_organization_id' => $organization->id,
            'role' => $role,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function token(AdvertiserAccount $account, array $abilities = ['advertiser:session'], int $minutes = 15): string
    {
        return $account->createToken('advertiser-test', $abilities, now()->addMinutes($minutes))->plainTextToken;
    }

    private function url(AdvertiserOrganization $organization, ?AdvertiserCampaign $campaign = null): string
    {
        $path = '/api/v1/advertiser/organizations/'.$organization->id.'/campaigns';

        return $campaign ? $path.'/'.$campaign->id : $path;
    }

    private function campaign(AdvertiserOrganization $organization): AdvertiserCampaign
    {
        return AdvertiserCampaign::create([
            'advertiser_organization_id' => $organization->id,
            'title' => 'Original campaign draft',
            'body' => 'This draft is private and not approved for publication.',
        ]);
    }

    private function data(): array
    {
        return [
            'title' => 'Original advertiser brief',
            'body' => 'This is only a draft without advertising delivery approval.',
        ];
    }

    public function test_account_only_lists_active_organizations_with_active_membership(): void
    {
        $account = $this->account();
        $own = $this->organization();
        $inactiveMembership = $this->organization();
        $inactiveOrganization = $this->organization(false);
        $other = $this->organization();
        $this->member($account, $own);
        $this->member($account, $inactiveMembership, 'viewer', false);
        $this->member($account, $inactiveOrganization);
        $this->member($this->account(), $other);

        $this->withToken($this->token($account))
            ->getJson('/api/v1/advertiser/organizations')
            ->assertOk()->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.id', $own->id)
            ->assertJsonPath('organizations.0.role', 'owner')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_owner_creates_and_reads_only_own_private_campaign_drafts(): void
    {
        $owner = $this->account();
        $org = $this->organization();
        $this->member($owner, $org);
        $response = $this->withToken($this->token($owner))
            ->postJson($this->url($org), $this->data())
            ->assertCreated()->assertJsonPath('campaign.status', 'draft')
            ->assertJsonPath('campaign.revision', 1)
            ->assertJsonMissingPath('campaign.advertiser_organization_id');
        $campaign = AdvertiserCampaign::firstOrFail();

        $this->assertSame($org->id, $campaign->advertiser_organization_id);
        $this->assertSame($campaign->id, $response->json('campaign.id'));
        $raw = DB::table('advertiser_campaigns')->where('id', $campaign->id)->first();
        $this->assertNotSame($campaign->title, $raw->title);
        $this->assertNotSame($campaign->body, $raw->body);

        $this->getJson($this->url($org))->assertOk()
            ->assertJsonCount(1, 'campaigns')
            ->assertJsonMissingPath('campaigns.0.body');
        $this->getJson($this->url($org, $campaign))->assertOk()
            ->assertJsonPath('campaign.body', $this->data()['body']);
    }

    public function test_cross_organization_idor_denies_list_read_update_and_create(): void
    {
        $owner = $this->account();
        $own = $this->organization();
        $foreign = $this->organization();
        $this->member($owner, $own);
        $this->member($this->account(), $foreign);
        $foreignCampaign = $this->campaign($foreign);

        $this->withToken($this->token($owner))
            ->getJson($this->url($foreign))->assertNotFound();
        $this->getJson($this->url($foreign, $foreignCampaign))->assertNotFound();
        $this->postJson($this->url($foreign), $this->data())->assertNotFound();
        $this->patchJson($this->url($foreign, $foreignCampaign), [
            'title' => 'Unauthorized change',
            'revision' => 1,
        ])->assertNotFound();

        // A foreign record must remain undiscoverable even under a valid owned URL.
        $this->getJson($this->url($own, $foreignCampaign))->assertNotFound();
        $this->patchJson($this->url($own, $foreignCampaign), [
            'title' => 'Unauthorized change',
            'revision' => 1,
        ])->assertNotFound();

        $this->assertSame(1, $foreignCampaign->fresh()->revision);
        $this->assertSame('Original campaign draft', $foreignCampaign->fresh()->title);
        $this->assertDatabaseCount('advertiser_campaigns', 1);
    }

    public function test_multiple_memberships_are_isolated_by_route_organization_and_role(): void
    {
        $account = $this->account();
        $owned = $this->organization();
        $viewOnly = $this->organization();
        $this->member($account, $owned, 'owner');
        $this->member($account, $viewOnly, 'viewer');
        $draft = $this->campaign($viewOnly);

        $this->withToken($this->token($account))
            ->getJson($this->url($viewOnly, $draft))->assertOk();
        $this->postJson($this->url($viewOnly), $this->data())->assertForbidden();
        $this->patchJson($this->url($viewOnly, $draft), [
            'revision' => 1, 'title' => 'Unauthorized viewer edit',
        ])->assertForbidden();
        $this->postJson($this->url($owned), $this->data())->assertCreated();
        $this->assertSame(1, $draft->fresh()->revision);
    }

    public function test_editor_can_edit_but_revision_conflicts_and_reviewed_state_are_denied(): void
    {
        $account = $this->account();
        $org = $this->organization();
        $this->member($account, $org, 'editor');
        $draft = $this->campaign($org);

        $this->withToken($this->token($account))
            ->patchJson($this->url($org, $draft), [
                'revision' => 1, 'title' => 'Authorized editor change',
            ])->assertOk()->assertJsonPath('campaign.revision', 2);
        $this->patchJson($this->url($org, $draft), [
            'revision' => 1, 'title' => 'Stale editor change',
        ])->assertStatus(409);
        $draft->forceFill(['status' => 'in_review'])->save();
        $this->patchJson($this->url($org, $draft), [
            'revision' => 2, 'title' => 'Edited without review clearance',
        ])->assertForbidden();
    }

    public function test_membership_role_change_revocation_and_organization_deactivation_apply_immediately(): void
    {
        $account = $this->account();
        $org = $this->organization();
        $this->member($account, $org);
        $draft = $this->campaign($org);
        $this->withToken($this->token($account));

        DB::table('advertiser_memberships')
            ->where('advertiser_account_id', $account->id)->where('advertiser_organization_id', $org->id)
            ->update(['role' => 'viewer']);
        $this->patchJson($this->url($org, $draft), [
            'revision' => 1, 'title' => 'Role changed immediately',
        ])->assertForbidden();

        DB::table('advertiser_memberships')
            ->where('advertiser_account_id', $account->id)->where('advertiser_organization_id', $org->id)
            ->update(['is_active' => false]);
        $this->getJson($this->url($org, $draft))->assertNotFound();

        DB::table('advertiser_memberships')
            ->where('advertiser_account_id', $account->id)->where('advertiser_organization_id', $org->id)
            ->update(['is_active' => true]);
        $org->forceFill(['is_active' => false])->save();
        $this->getJson($this->url($org, $draft))->assertNotFound();
    }

    public function test_identity_deactivation_verification_token_expiration_and_revocation_fail_closed(): void
    {
        $account = $this->account();
        $org = $this->organization();
        $this->member($account, $org);
        $access = $this->token($account);

        $this->getJson($this->url($org))->assertUnauthorized();
        $this->withToken($access)->getJson($this->url($org))->assertOk();

        $account->forceFill(['is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->withToken($access)->getJson($this->url($org))->assertForbidden();

        $account->forceFill(['is_active' => true, 'email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->getJson($this->url($org))->assertForbidden();

        $account->forceFill(['email_verified_at' => now()])->save();
        $account->tokens()->delete();
        app('auth')->forgetGuards();
        $this->getJson($this->url($org))->assertUnauthorized();
        $this->withToken($this->token($account, minutes: -1))
            ->getJson($this->url($org))->assertUnauthorized();
    }

    public function test_reader_staff_and_wrong_scope_tokens_cannot_become_advertiser(): void
    {
        $org = $this->organization();
        $account = $this->account();
        $this->member($account, $org);
        $reader = User::factory()->create();
        $staff = StaffMember::create([
            'name' => 'Ad Operations',
            'email' => Str::uuid().'@example.test',
            'password' => 'Password!Secure2026',
            'role' => 'ads',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        foreach ([
            $reader->createToken('reader', ['advertiser:session'], now()->addMinutes(15))->plainTextToken,
            $staff->createToken('staff', ['advertiser:session'], now()->addMinutes(15))->plainTextToken,
            $this->token($account, ['reader:read']),
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson($this->url($org))->assertForbidden();
            $this->postJson($this->url($org), $this->data())->assertForbidden();
        }
        $this->assertDatabaseCount('advertiser_campaigns', 0);
    }

    public function test_tampered_fields_are_rejected_and_no_campaign_is_published(): void
    {
        $account = $this->account();
        $org = $this->organization();
        $this->member($account, $org);
        $this->withToken($this->token($account));

        foreach (['advertiser_organization_id', 'advertiser_account_id', 'status', 'id', 'revision'] as $key) {
            $this->postJson($this->url($org), [
                ...$this->data(), $key => $key === 'status' ? 'approved' : 999,
            ])->assertUnprocessable()->assertJsonValidationErrors($key);
        }

        $draft = $this->campaign($org);
        $this->patchJson($this->url($org, $draft), [
            'revision' => 1, 'advertiser_organization_id' => 999, 'title' => 'Other organization',
        ])->assertUnprocessable()->assertJsonValidationErrors('advertiser_organization_id');
        $this->postJson($this->url($org, $draft).'/publish', [])->assertNotFound();
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_organization_and_membership_constraints_reject_invalid_values(): void
    {
        $account = $this->account();
        $org = $this->organization();
        $this->member($account, $org);

        try {
            $this->member($account, $org);
            $this->fail('Duplicate membership should violate unique constraint.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }

        $invalidOrg = $this->organization();
        try {
            $this->member($account, $invalidOrg, 'superadmin');
            $this->fail('Invalid role should violate CHECK constraint.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }
}
