<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsAssignmentFilterInjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_selected_assignment_scope_is_rejected_without_disclosing_foreign_organizations(): void
    {
        $operators = [];

        foreach (range(1, 2) as $number) {
            $organization = AdvertiserOrganization::create([
                'name' => 'Advertiser '.Str::uuid(),
            ]);
            $organization->forceFill(['is_active' => true])->save();

            $staff = StaffMember::create([
                'name' => 'Ads '.$number,
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
                'organization' => $organization,
                'assignment' => $assignmentId,
                'token' => $staff->createToken(
                    'staff-step-up',
                    ['staff:session', 'staff:step-up'],
                    now()->addMinutes(5),
                )->plainTextToken,
            ];
        }

        foreach ([
            ['staff_member_id' => $operators[1]['staff']->id],
            ['advertiser_organization_id' => $operators[1]['organization']->id],
            ['include_inactive' => '1'],
            ['is_active' => '0'],
        ] as $filters) {
            app('auth')->forgetGuards();
            $this->withToken($operators[0]['token'])
                ->getJson('/api/v1/staff/ads/assignments?'.http_build_query($filters))
                ->assertUnprocessable()
                ->assertDontSee($operators[1]['organization']->name);
        }

        app('auth')->forgetGuards();
        $this->withToken($operators[0]['token'])
            ->getJson('/api/v1/staff/ads/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.id', $operators[0]['assignment'])
            ->assertDontSee($operators[1]['organization']->name);

        foreach ($operators as $operator) {
            $this->assertDatabaseHas('staff_advertiser_assignments', [
                'id' => $operator['assignment'],
                'staff_member_id' => $operator['staff']->id,
                'is_active' => true,
            ]);
        }

        $this->assertDatabaseCount('security_audit_events', 0);
    }
}
