<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsCrossPrincipalLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_ads_operator_logout_cannot_revoke_another_operators_session_or_assignment(): void
    {
        $organization = AdvertiserOrganization::create(['name' => 'Advertiser '.Str::uuid()]);
        $organization->forceFill(['is_active' => true])->save();

        $operators = [];
        foreach (['first', 'second'] as $label) {
            $staff = StaffMember::create([
                'name' => 'Ads '.$label,
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
            $operators[] = [
                'staff' => $staff,
                'assignment_id' => $assignmentId,
                'token' => $staff->createToken(
                    'staff-step-up',
                    ['staff:session', 'staff:step-up'],
                    now()->addMinutes(5),
                )->plainTextToken,
            ];
        }

        app('auth')->forgetGuards();
        $this->withToken($operators[0]['token'])
            ->postJson('/api/v1/staff/auth/logout')
            ->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($operators[0]['token'])
            ->getJson('/api/v1/staff/ads/assignments')
            ->assertUnauthorized();

        app('auth')->forgetGuards();
        $this->withToken($operators[1]['token'])
            ->getJson('/api/v1/staff/ads/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $operators[1]['assignment_id']);

        foreach ($operators as $operator) {
            $this->assertDatabaseHas('staff_advertiser_assignments', [
                'id' => $operator['assignment_id'],
                'staff_member_id' => $operator['staff']->id,
                'is_active' => true,
            ]);
        }

        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $operators[0]['staff']->id,
            'action' => 'staff.session.ended',
        ]);
        $this->assertDatabaseMissing('security_audit_events', [
            'staff_member_id' => $operators[1]['staff']->id,
            'action' => 'staff.session.ended',
        ]);
        $this->assertDatabaseCount('security_audit_events', 1);
    }
}
