<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsCrossOrganizationRelinquishTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_cannot_relinquish_another_operators_organization(): void
    {
        $organizations = [];
        $operators = [];

        foreach (range(1, 2) as $number) {
            $organization = AdvertiserOrganization::create([
                'name' => 'Advertiser '.Str::uuid(),
            ]);
            $organization->forceFill(['is_active' => true])->save();
            $organizations[] = $organization;

            $operator = StaffMember::create([
                'name' => 'Ads operator '.$number,
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

            $operators[] = [
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
        $this->withToken($operators[0]['token'])
            ->postJson('/api/v1/staff/ads/assignments/'.$organizations[1]->id.'/relinquish')
            ->assertNotFound();

        foreach ($operators as $operator) {
            $this->assertDatabaseHas('staff_advertiser_assignments', [
                'id' => $operator['assignment'],
                'staff_member_id' => $operator['id'],
                'is_active' => true,
            ]);
        }

        $this->assertDatabaseCount('security_audit_events', 0);

        foreach ($operators as $operator) {
            app('auth')->forgetGuards();
            $this->withToken($operator['token'])
                ->getJson('/api/v1/staff/ads/assignments')
                ->assertOk()
                ->assertJsonCount(1, 'assignments')
                ->assertJsonPath('assignments.0.id', $operator['assignment']);
        }
    }
}
