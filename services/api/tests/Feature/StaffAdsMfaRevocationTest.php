<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsMfaRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_mfa_enrollment_denies_existing_step_up_and_preserves_assignment(): void
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

        $token = $staff->createToken(
            'prior-step-up',
            ['staff:session', 'staff:step-up'],
            now()->addMinutes(5),
        )->plainTextToken;

        $staff->forceFill(['mfa_enrolled_at' => null])->save();
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/v1/staff/ads/assignments/'.$organization->id.'/relinquish')
            ->assertForbidden();

        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $assignmentId,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_expired_step_up_cannot_relinquish_assignment_or_write_audit(): void
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

        $expired = $staff->createToken(
            'expired-step-up',
            ['staff:session', 'staff:step-up'],
            now()->subSecond(),
        )->plainTextToken;

        app('auth')->forgetGuards();
        $this->withToken($expired)
            ->postJson('/api/v1/staff/ads/assignments/'.$organization->id.'/relinquish')
            ->assertUnauthorized();

        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $assignmentId,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }
}
