<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\Sport;
use App\Models\StaffMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReaderPrivacyRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secure!PrivacyPassword2026';

    private function reader(): User
    {
        return User::factory()->create(['password' => self::PASSWORD]);
    }

    private function asReader(User $reader, array $abilities = ['reader:read', 'reader:write'], int $minutes = 30): self
    {
        $token = $reader->createToken('reader-privacy-test', $abilities, now()->addMinutes($minutes))->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token);

        return $this;
    }

    public function test_export_is_password_reverified_and_contains_only_the_owned_privacy_record(): void
    {
        $reader = $this->reader();
        $other = $this->reader();
        $sport = Sport::create(['slug' => 'test-sport', 'name' => 'Test Sport']);
        $team = Team::create(['sport_id' => $sport->id, 'slug' => 'test-club', 'name' => 'Test Club']);
        $this->asReader($reader)->patchJson('/api/v1/me/preferences', [
            'preferred_locale' => 'fr',
            'analytics_consent' => true,
            'personalized_news_enabled' => true,
        ])->assertOk();
        $this->putJson('/api/v1/me/favorites', [
            'sport_ids' => [$sport->id], 'team_ids' => [$team->id],
        ])->assertOk();

        $this->asReader($other)->patchJson('/api/v1/me/preferences', [
            'analytics_consent' => true,
        ])->assertOk();

        $response = $this->asReader($reader)
            ->postJson('/api/v1/me/privacy/export', ['password' => self::PASSWORD])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('export.account.id', $reader->id)
            ->assertJsonPath('export.preferences.preferred_locale', 'fr')
            ->assertJsonPath('export.preferences.analytics_consent', true)
            ->assertJsonPath('export.favorites.sport_ids.0', $sport->id)
            ->assertJsonPath('export.favorites.team_ids.0', $team->id)
            ->assertJsonCount(2, 'export.consent_history')
            ->assertJsonPath('export.deletion_request', null);

        $encoded = $response->getContent();
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString($reader->password, $encoded);
        $this->assertStringNotContainsString($other->email, $encoded);
        $this->assertDatabaseCount('reader_deletion_requests', 0);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_export_requires_correct_password_and_explicit_write_scope(): void
    {
        $reader = $this->reader();
        $this->postJson('/api/v1/me/privacy/export', ['password' => self::PASSWORD])->assertUnauthorized();
        $this->asReader($reader, ['reader:read'])
            ->postJson('/api/v1/me/privacy/export', ['password' => self::PASSWORD])->assertForbidden();

        $this->asReader($reader);
        $this->postJson('/api/v1/me/privacy/export', [])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/me/privacy/export', ['password' => 'incorrect'])->assertForbidden();
        $this->postJson('/api/v1/me/privacy/export', [
            'password' => self::PASSWORD, 'user_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('reader_deletion_requests', 0);
    }

    public function test_consent_changes_record_only_real_transitions_and_do_not_track_default_opt_out(): void
    {
        $reader = $this->reader();
        $this->asReader($reader);
        $this->getJson('/api/v1/me/preferences')
            ->assertOk()->assertJsonPath('preferences.analytics_consent', false);
        $this->assertDatabaseCount('reader_consent_events', 0);

        $this->patchJson('/api/v1/me/preferences', [
            'analytics_consent' => false, 'preferred_locale' => 'de',
        ])->assertOk();
        $this->patchJson('/api/v1/me/preferences', ['analytics_consent' => true])->assertOk();
        $this->patchJson('/api/v1/me/preferences', ['analytics_consent' => true])->assertOk();
        $this->patchJson('/api/v1/me/preferences', [
            'analytics_consent' => false, 'personalized_news_enabled' => true,
        ])->assertOk();
        $this->assertDatabaseCount('reader_consent_events', 3);
        $this->assertDatabaseHas('reader_consent_events', [
            'user_id' => $reader->id, 'consent_key' => 'analytics_consent', 'granted' => false,
        ]);
        $this->postJson('/api/v1/me/privacy/export', ['password' => self::PASSWORD])
            ->assertOk()->assertJsonCount(3, 'export.consent_history')
            ->assertJsonPath('export.preferences.analytics_consent', false)
            ->assertJsonPath('export.preferences.personalized_news_enabled', true);
    }

    public function test_database_consent_ledger_is_append_only(): void
    {
        $reader = $this->reader();
        $this->asReader($reader)->patchJson('/api/v1/me/preferences', [
            'analytics_consent' => true,
        ])->assertOk();
        $id = DB::table('reader_consent_events')->value('id');

        foreach ([
            fn () => DB::table('reader_consent_events')->where('id', $id)->update(['granted' => false]),
            fn () => DB::table('reader_consent_events')->where('id', $id)->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Append-only consent ledger was mutated.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('reader_consent_events', 1);
    }

    public function test_deletion_request_is_idempotent_requires_password_and_does_not_delete_any_data(): void
    {
        $reader = $this->reader();
        $this->asReader($reader);
        $this->postJson('/api/v1/me/privacy/deletion-requests', [
            'password' => 'incorrect',
        ])->assertForbidden();
        $this->assertDatabaseCount('reader_deletion_requests', 0);

        $response = $this->postJson('/api/v1/me/privacy/deletion-requests', [
            'password' => self::PASSWORD,
        ])->assertStatus(202)->assertJsonPath('request.status', 'pending_legal_review')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('message', 'Deletion request recorded; account data has not been deleted.');
        $requestId = $response->json('request.id');
        $this->postJson('/api/v1/me/privacy/deletion-requests', [
            'password' => self::PASSWORD,
        ])->assertStatus(202)->assertJsonPath('request.id', $requestId);

        $this->getJson('/api/v1/me/privacy/deletion-request')->assertOk()
            ->assertJsonPath('request.id', $requestId)
            ->assertJsonPath('request.status', 'pending_legal_review');
        $this->postJson('/api/v1/me/privacy/export', [
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('export.deletion_request.id', $requestId);
        $this->assertDatabaseCount('reader_deletion_requests', 1);
        $this->assertDatabaseHas('users', ['id' => $reader->id, 'email' => $reader->email]);
        $this->assertGreaterThan(0, $reader->tokens()->count());
    }

    public function test_readers_cannot_view_or_create_each_others_deletion_requests(): void
    {
        $owner = $this->reader();
        $other = $this->reader();
        $created = $this->asReader($owner)
            ->postJson('/api/v1/me/privacy/deletion-requests', [
                'password' => self::PASSWORD,
            ])->assertStatus(202)->json('request.id');
        $this->asReader($other)->getJson('/api/v1/me/privacy/deletion-request')
            ->assertOk()->assertJsonPath('request', null);
        $this->postJson('/api/v1/me/privacy/deletion-requests', [
            'password' => self::PASSWORD,
            'user_id' => $owner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $otherId = $this->postJson('/api/v1/me/privacy/deletion-requests', [
            'password' => self::PASSWORD,
        ])->assertStatus(202)->json('request.id');
        $this->assertNotSame($created, $otherId);
        $this->assertDatabaseCount('reader_deletion_requests', 2);
    }

    public function test_expired_revoked_and_unverified_reader_cannot_export_data(): void
    {
        $reader = $this->reader();
        $this->asReader($reader, minutes: -1)
            ->postJson('/api/v1/me/privacy/export', ['password' => self::PASSWORD])
            ->assertUnauthorized();

        $this->asReader($reader)->postJson('/api/v1/me/privacy/export', [
            'password' => self::PASSWORD,
        ])->assertOk();
        $reader->tokens()->delete();
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/me/privacy/export', ['password' => self::PASSWORD])
            ->assertUnauthorized();

        $this->asReader($reader);
        $reader->forceFill(['email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/me/privacy/export', ['password' => self::PASSWORD])
            ->assertForbidden();
    }

    public function test_reader_privacy_routes_are_not_available_to_staff_or_advertisers(): void
    {
        $staff = StaffMember::create([
            'name' => 'Support Operator',
            'email' => Str::uuid().'@example.test',
            'role' => 'support',
            'password' => self::PASSWORD,
        ]);
        $staff->forceFill([
            'is_active' => true, 'email_verified_at' => now(), 'mfa_enrolled_at' => now(),
        ])->save();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Advertiser Operator',
            'email' => Str::uuid().'@example.test',
            'password' => self::PASSWORD,
        ]);

        foreach ([
            $staff->createToken('staff-privacy', ['reader:read', 'reader:write'], now()->addMinutes(30))->plainTextToken,
            $advertiser->createToken('ads-privacy', ['reader:read', 'reader:write'], now()->addMinutes(30))->plainTextToken,
        ] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)
                ->postJson('/api/v1/me/privacy/export', ['password' => self::PASSWORD])
                ->assertForbidden();
            $this->postJson('/api/v1/me/privacy/deletion-requests', [
                'password' => self::PASSWORD,
            ])->assertForbidden();
            $this->getJson('/api/v1/me/privacy/deletion-request')->assertForbidden();
        }
        $this->assertDatabaseCount('reader_deletion_requests', 0);
    }

    public function test_privacy_actions_reject_client_controlled_retention_and_account_fields(): void
    {
        $reader = $this->reader();
        $this->asReader($reader);
        foreach ([
            'email' => 'elsewhere@example.test',
            'status' => 'deleted',
            'retention_override' => true,
        ] as $key => $value) {
            $this->postJson('/api/v1/me/privacy/deletion-requests', [
                'password' => self::PASSWORD, $key => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseCount('reader_deletion_requests', 0);
        $this->assertDatabaseCount('users', 1);
    }
}
