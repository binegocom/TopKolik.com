<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserOrganization;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffAdsAssignmentPaginationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cursor_pagination_never_leaks_foreign_or_inactive_assignments(): void
    {
        $owner = $this->staff('Owner');
        $other = $this->staff('Other');
        $ownIds = [];

        foreach (range(1, 4) as $number) {
            $organization = $this->organization('Own '.$number);
            $ownIds[] = $this->assign($owner, $organization, $number !== 2);
            $this->assign($other, $organization, true);
        }

        $foreignOrganization = $this->organization('Foreign');
        $foreignId = $this->assign($other, $foreignOrganization, true);

        $token = $owner->createToken(
            'staff-session',
            ['staff:session'],
            now()->addMinutes(5),
        )->plainTextToken;

        $visible = array_values(array_reverse(array_filter(
            $ownIds,
            static fn (int $id): bool => $id !== $ownIds[1],
        )));

        $cursor = null;
        $observed = [];
        foreach (range(1, 4) as $page) {
            app('auth')->forgetGuards();
            $query = ['limit' => 1];
            if ($cursor !== null) {
                $query['before_id'] = $cursor;
            }
            $response = $this->withToken($token)
                ->getJson('/api/v1/staff/ads/assignments?'.http_build_query($query))
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');

            $items = $response->json('assignments');
            $this->assertCount($page <= 3 ? 1 : 0, $items);
            if ($items !== []) {
                $observed[] = $items[0]['id'];
                $this->assertNotEquals($foreignId, $items[0]['id']);
                $this->assertNotEquals($ownIds[1], $items[0]['id']);
            }
            $cursor = $response->json('next_before_id');
            if ($cursor === null) {
                break;
            }
        }

        $this->assertSame($visible, $observed);
        $this->assertNull($cursor);
        $this->assertDatabaseHas('staff_advertiser_assignments', [
            'id' => $foreignId,
            'staff_member_id' => $other->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    private function staff(string $name): StaffMember
    {
        $staff = StaffMember::create([
            'name' => $name,
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!Staff2026',
            'role' => 'ads',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function organization(string $name): AdvertiserOrganization
    {
        $organization = AdvertiserOrganization::create(['name' => $name.' '.Str::uuid()]);
        $organization->forceFill(['is_active' => true])->save();

        return $organization;
    }

    private function assign(StaffMember $staff, AdvertiserOrganization $organization, bool $active): int
    {
        return DB::table('staff_advertiser_assignments')->insertGetId([
            'staff_member_id' => $staff->id,
            'advertiser_organization_id' => $organization->id,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
