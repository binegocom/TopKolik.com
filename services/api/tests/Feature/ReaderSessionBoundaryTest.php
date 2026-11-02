<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReaderSessionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): User
    {
        return User::factory()->create();
    }

    private function token(User|StaffMember|AdvertiserAccount $account, array $abilities, bool $expiring = true): string
    {
        return $account->createToken(
            'reader-boundary-test',
            $abilities,
            $expiring ? now()->addMinutes(30) : null,
        )->plainTextToken;
    }

    private function bearer(string $token): void
    {
        app('auth')->forgetGuards();
        $this->withToken($token);
    }

    public function test_valid_verified_reader_can_use_existing_read_write_and_logout_routes(): void
    {
        $reader = $this->reader();
        $this->bearer($this->token($reader, ['reader:read', 'reader:write']));

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.id', $reader->id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('user.id', $reader->id)
            ->assertHeader('Pragma', 'no-cache');
        $this->getJson('/api/v1/me/preferences')->assertOk();
        $this->getJson('/api/v1/me/favorites')->assertOk();
        $this->patchJson('/api/v1/me', ['name' => 'Updated Verified Reader'])
            ->assertOk()->assertJsonPath('user.name', 'Updated Verified Reader')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson('/api/v1/me/preferences', ['analytics_consent' => true])
            ->assertOk()->assertJsonPath('preferences.analytics_consent', true);
        $this->putJson('/api/v1/me/favorites', [
            'sport_ids' => [], 'team_ids' => [],
        ])->assertOk();
        $this->getJson('/api/v1/me/privacy/consent-history')->assertOk()
            ->assertJsonCount(1, 'consent_history');

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_staff_and_advertiser_tokens_cannot_cross_reader_boundary_even_with_reader_abilities(): void
    {
        $reader = $this->reader();
        $staff = StaffMember::create([
            'name' => 'Cross Principal Staff',
            'email' => Str::uuid().'@example.test',
            'role' => 'support',
            'password' => 'Strong!ReaderBoundary2026',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Cross Principal Advertiser',
            'email' => Str::uuid().'@example.test',
            'password' => 'Strong!ReaderBoundary2026',
        ]);

        // Separate identity tables may have overlapping numeric IDs.
        $this->assertSame($reader->id, $staff->id);
        $this->assertSame($reader->id, $advertiser->id);

        foreach ([$staff, $advertiser] as $principal) {
            $this->bearer($this->token($principal, ['reader:read', 'reader:write']));
            foreach ([
                '/api/v1/auth/me',
                '/api/v1/me',
                '/api/v1/me/preferences',
                '/api/v1/me/favorites',
                '/api/v1/me/privacy/consent-history',
                '/api/v1/me/privacy/deletion-request',
            ] as $path) {
                $this->getJson($path)->assertForbidden();
            }
            $this->patchJson('/api/v1/me', [
                'name' => 'Unauthorized change',
            ])->assertForbidden();
            $this->patchJson('/api/v1/me/preferences', [
                'analytics_consent' => true,
            ])->assertForbidden();
            $this->putJson('/api/v1/me/favorites', [
                'sport_ids' => [], 'team_ids' => [],
            ])->assertForbidden();
            $this->postJson('/api/v1/me/privacy/export', [
                'password' => 'Strong!ReaderBoundary2026',
            ])->assertForbidden();
            $this->postJson('/api/v1/me/privacy/deletion-requests', [
                'password' => 'Strong!ReaderBoundary2026',
            ])->assertForbidden();
            $this->postJson('/api/v1/auth/logout')->assertForbidden();
        }

        $this->assertNotSame('Unauthorized change', $reader->fresh()->name);
        $this->assertDatabaseCount('reader_preferences', 0);
        $this->assertDatabaseCount('reader_consent_events', 0);
        $this->assertDatabaseCount('reader_deletion_requests', 0);
    }

    public function test_legacy_reader_token_without_expiry_is_denied_before_account_routes(): void
    {
        $reader = $this->reader();
        $this->bearer($this->token($reader, ['reader:read', 'reader:write'], false));

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me/preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/me', ['name' => 'Should Not Save'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->assertNotSame('Should Not Save', $reader->fresh()->name);
    }

    public function test_unverified_reader_with_preexisting_token_is_denied_everywhere(): void
    {
        $reader = $this->reader();
        $this->bearer($this->token($reader, ['reader:read', 'reader:write']));
        $reader->forceFill(['email_verified_at' => null])->save();
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->getJson('/api/v1/me/preferences')->assertForbidden();
        $this->patchJson('/api/v1/me/preferences', [
            'analytics_consent' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('reader_consent_events', 0);
    }

    public function test_expired_revoked_and_wrong_scope_reader_tokens_fail_closed(): void
    {
        $reader = $this->reader();
        $this->bearer($reader->createToken(
            'expired-reader', ['reader:read'], now()->subMinute(),
        )->plainTextToken);
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $this->bearer($this->token($reader, ['reader:write']));
        $this->getJson('/api/v1/me')->assertForbidden();
        $this->patchJson('/api/v1/me', ['name' => 'Valid Write'])
            ->assertOk();

        $this->bearer($this->token($reader, ['reader:read']));
        $reader->tokens()->delete();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
