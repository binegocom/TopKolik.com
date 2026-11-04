<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsSelfRelinquishIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_relinquish_does_not_change_another_staff_assignment(): void
    {
        $organization = AdvertiserOrganization::create(['name' => 'Advertiser '.Str::uuid()]);
        $organization->forceFill(['is_active' => true])->save();

        $staff = [];
        foreach (range(1, 2) as $index) {
            $operator = StaffMember::create([
                'name' => 'Operator '.$index,
                'email' => Str::uuid().'@example.test',
                'password' => 'Strong!Staff2026',
                'role' => 'ads',
            ]);
            $operator->forceFill([
                'is_active' => true,
                'email_verified_at' => now(),
                'mfa_enrolled_at' => now(),
            ])->save();
            $assignment = DB::table('staff_advertiser_assignments')->insertGetId([
                'staff_member_id' => $operator->id,
                'advertiser_organization_id' => $organization->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $staff[] = [
                'id' => $operator->id,
                'assignment' => $assignment,
                'token' => $operator->createToken(
                    'staff-step-up',
                    ['staff:session', 'staff:step-up'],
                    now()->addMinutes(5),
                )->plainTextToken,
            ];
        }

        app('auth')->forgetGuards();
        $this->withToken($staff[0]['token'])
            ->postJson('/api/v1/staff/ads/assignments/'.$organization->id.'/relinquish')
            ->assertOk()
            ->assertJsonPath('assignment_status', 'relinquished');

        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $staff[0]['assignment'],
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $staff[1]['assignment'],
            'is_active' => true,
        ]);

        app('auth')->forgetGuards();
        $this->withToken($staff[1]['token'])
            ->getJson('/api/v1/staff/ads/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $staff[1]['assignment']);

        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $staff[0]['id'],
            'action' => 'staff.advertiser_assignment.relinquished',
        ]);
        $this->assertDatabaseMissing('security_audit_events', [
            'staff_member_id' => $staff[1]['id'],
            'action' => 'staff.advertiser_assignment.relinquished',
        ]);
    }
}
