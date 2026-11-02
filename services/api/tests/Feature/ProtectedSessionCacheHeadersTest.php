<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ProtectedSessionCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Cache Boundary Staff',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!CacheBoundary2026',
            'role' => 'editor',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function advertiser(): AdvertiserAccount
    {
        $account = AdvertiserAccount::create([
            'name' => 'Cache Boundary Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!CacheBoundary2026',
        ]);
        $account->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        return $account;
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Pragma', 'no-cache');
    }

    public function test_staff_success_role_denial_and_deactivation_are_never_cacheable(): void
    {
        $staff = $this->staff();
        $token = $staff->createToken(
            'staff-cache-boundary', ['staff:session'], now()->addMinutes(15),
        )->plainTextToken;

        $this->assertPrivate(
            $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertOk(),
        );
        $this->assertPrivate(
            $this->getJson('/api/v1/staff/support/tickets')->assertForbidden(),
        );

        $staff->forceFill(['is_active' => false])->save();
        app('auth')->forgetGuards();

        $this->assertPrivate(
            $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertForbidden(),
        );
    }

    public function test_non_expiring_staff_token_denial_is_never_cacheable(): void
    {
        $staff = $this->staff();
        $legacy = $staff->createToken('legacy-staff', ['staff:session'])->plainTextToken;

        $this->assertPrivate(
            $this->withToken($legacy)->getJson('/api/v1/staff/auth/me')->assertUnauthorized(),
        );
    }

    public function test_advertiser_success_scope_denial_and_deactivation_are_never_cacheable(): void
    {
        $account = $this->advertiser();
        $token = $account->createToken(
            'advertiser-cache-boundary', ['advertiser:session'], now()->addMinutes(15),
        )->plainTextToken;

        $this->assertPrivate(
            $this->withToken($token)->getJson('/api/v1/advertiser/organizations')->assertOk(),
        );

        $wrongScope = $account->createToken(
            'advertiser-wrong-scope', ['reader:read'], now()->addMinutes(15),
        )->plainTextToken;
        app('auth')->forgetGuards();
        $this->assertPrivate(
            $this->withToken($wrongScope)->getJson('/api/v1/advertiser/organizations')->assertForbidden(),
        );

        $account->forceFill(['is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->assertPrivate(
            $this->withToken($token)->getJson('/api/v1/advertiser/organizations')->assertForbidden(),
        );
    }

    public function test_non_expiring_advertiser_token_denial_is_never_cacheable(): void
    {
        $account = $this->advertiser();
        $legacy = $account->createToken(
            'legacy-advertiser', ['advertiser:session'],
        )->plainTextToken;

        $this->assertPrivate(
            $this->withToken($legacy)->getJson('/api/v1/advertiser/organizations')->assertUnauthorized(),
        );
    }
}
