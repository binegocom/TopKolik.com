<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsTokenRotationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoking_one_staff_bearer_does_not_authorize_it_through_another_live_session(): void
    {
        $staff = StaffMember::create([
            'name' => 'Ads Reviewer',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!Staff2026',
            'role' => 'ads',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        $organization = AdvertiserOrganization::create([
            'name' => 'Organization '.Str::uuid(),
        ]);
        $organization->forceFill(['is_active' => true])->save();

        $assignmentId = DB::table('staff_advertiser_assignments')->insertGetId([
            'staff_member_id' => $staff->id,
            'advertiser_organization_id' => $organization->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revoked = $staff->createToken(
            'previous-session',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;
        $active = $staff->createToken(
            'current-session',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;

        $staff->tokens()->where('name', 'previous-session')->delete();
        $url = '/api/v1/staff/ads/assignments/'.$organization->id.'/relinquish';

        app('auth')->forgetGuards();
        $this->withToken($revoked)->postJson($url)->assertUnauthorized();
        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $assignmentId,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);

        app('auth')->forgetGuards();
        $this->withToken($active)->postJson($url)->assertOk()
            ->assertJsonPath('assignment_status', 'relinquished');
        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $assignmentId,
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);

        app('auth')->forgetGuards();
        $this->withToken($revoked)->postJson($url)->assertUnauthorized();
        $this->assertDatabaseCount('security_audit_events', 1);
    }
}
