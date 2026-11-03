<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsLogoutRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_only_presented_step_up_bearer_without_relinquishing_assignment(): void
    {
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

        $organization = AdvertiserOrganization::create(['name' => 'Private advertiser '.Str::uuid()]);
        $organization->forceFill(['is_active' => true])->save();
        $assignmentId = DB::table('staff_advertiser_assignments')->insertGetId([
            'staff_member_id' => $staff->id,
            'advertiser_organization_id' => $organization->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revoked = $staff->createToken(
            'staff-step-up',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;
        $surviving = $staff->createToken(
            'staff-step-up',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;

        app('auth')->forgetGuards();
        $this->withToken($revoked)
            ->postJson('/api/v1/staff/auth/logout')
            ->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($revoked)
            ->postJson('/api/v1/staff/ads/assignments/'.$organization->id.'/relinquish')
            ->assertUnauthorized();

        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $assignmentId,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff->id,
            'action' => 'staff.session.ended',
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);

        app('auth')->forgetGuards();
        $this->withToken($surviving)
            ->getJson('/api/v1/staff/ads/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $assignmentId);
        $this->assertDatabaseCount('security_audit_events', 1);
    }
}
