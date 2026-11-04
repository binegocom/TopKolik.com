<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsRelinquishStepUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_without_step_up_cannot_relinquish_an_assignment(): void
    {
        $organization = AdvertiserOrganization::create(['name' => 'Advertiser '.Str::uuid()]);
        $organization->forceFill(['is_active' => true])->save();

        $staff = StaffMember::create([
            'name' => 'Ads Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!Staff2026',
            'role' => 'ads',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        $assignmentId = DB::table('staff_advertiser_assignments')->insertGetId([
            'staff_member_id' => $staff->id,
            'advertiser_organization_id' => $organization->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $staff->createToken(
            'staff-session',
            ['staff:session'],
            now()->addMinutes(15),
        )->plainTextToken;

        app('auth')->forgetGuards();
        $this->withToken($token)
            ->postJson('/api/v1/staff/ads/assignments/'.$organization->id.'/relinquish')
            ->assertForbidden();

        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $assignmentId,
            'staff_member_id' => $staff->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);

        app('auth')->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/staff/ads/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $assignmentId);
    }
}
