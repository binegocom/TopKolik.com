<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReaderProfilePreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function reader(string $abilities = 'reader:read,reader:write'): User
    {
        $user = User::factory()->create();

        return $user;
    }

    private function withReaderToken(User $user, array $abilities = ['reader:read', 'reader:write']): self
    {
        $token = $user->createToken('reader-test', $abilities)->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token);

        return $this;
    }

    public function test_guest_cannot_read_or_write_account_specific_data(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me/preferences')->assertUnauthorized();
        $this->getJson('/api/v1/me/favorites')->assertUnauthorized();
        $this->patchJson('/api/v1/me', ['name' => 'Changed'])->assertUnauthorized();
        $this->patchJson('/api/v1/me/preferences', ['analytics_consent' => true])->assertUnauthorized();
        $this->putJson('/api/v1/me/favorites', ['sport_ids' => [], 'team_ids' => []])->assertUnauthorized();
    }

    public function test_default_preferences_do_not_create_tracking_records(): void
    {
        $user = $this->reader();
        $this->withReaderToken($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('preferences.preferred_locale', null)
            ->assertJsonPath('preferences.effective_locale', 'en')
            ->assertJsonPath('preferences.analytics_consent', false)
            ->assertJsonPath('preferences.personalized_news_enabled', false)
            ->assertJsonPath('preferences.reading_history.enabled', false)
            ->assertJsonPath('preferences.reading_history.supported', false)
            ->assertJsonCount(0, 'favorites.sports')
            ->assertJsonCount(0, 'favorites.teams');

        $this->assertDatabaseCount('reader_preferences', 0);
        $this->assertDatabaseCount('reader_favorite_sports', 0);
        $this->assertDatabaseCount('reader_favorite_teams', 0);
        $this->assertDatabaseCount('sports', 0);
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_accept_language_uses_supported_base_language_and_falls_back_to_english(): void
    {
        $user = $this->reader();
        $this->withReaderToken($user)
            ->withHeader('Accept-Language', 'fr-CH,fr;q=0.9,es;q=0.6')
            ->getJson('/api/v1/me/preferences')->assertOk()
            ->assertJsonPath('preferences.effective_locale', 'fr');

        $this->withHeader('Accept-Language', 'tr-TR,tr;q=0.9')
            ->getJson('/api/v1/me/preferences')->assertOk()
            ->assertJsonPath('preferences.effective_locale', 'en');

        $this->withHeader('Accept-Language', 'de-CH,de;q=0.9')
            ->getJson('/api/v1/me/preferences')->assertOk()
            ->assertJsonPath('preferences.effective_locale', 'de');
    }

    public function test_profile_name_can_be_updated_without_changing_identity_fields(): void
    {
        $user = $this->reader();
        $this->withReaderToken($user)
            ->patchJson('/api/v1/me', ['name' => ' Updated Reader '])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Reader')
            ->assertJsonPath('user.email', $user->email);

        $this->assertSame('Updated Reader', $user->fresh()->name);
        $this->patchJson('/api/v1/me', ['name' => 'Hijack', 'email' => 'attacker@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson('/api/v1/me', ['name' => 'Hijack', 'user_id' => 923])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame('Updated Reader', $user->fresh()->name);
    }

    public function test_preferences_are_persisted_and_do_not_leak_to_other_users(): void
    {
        $first = $this->reader();
        $other = $this->reader();

        $this->withReaderToken($first)
            ->patchJson('/api/v1/me/preferences', [
                'preferred_locale' => 'it',
                'personalized_news_enabled' => true,
                'analytics_consent' => true,
            ])->assertOk()
            ->assertJsonPath('preferences.effective_locale', 'it')
            ->assertJsonPath('preferences.analytics_consent', true)
            ->assertJsonPath('preferences.personalized_news_enabled', true);

        $this->patchJson('/api/v1/me/preferences', ['analytics_consent' => false])
            ->assertOk()
            ->assertJsonPath('preferences.effective_locale', 'it')
            ->assertJsonPath('preferences.analytics_consent', false)
            ->assertJsonPath('preferences.personalized_news_enabled', true);

        $this->assertDatabaseHas('reader_preferences', [
            'user_id' => $first->id,
            'preferred_locale' => 'it',
            'analytics_consent' => false,
            'personalized_news_enabled' => true,
        ]);

        $this->withReaderToken($other)
            ->getJson('/api/v1/me/preferences')->assertOk()
            ->assertJsonPath('preferences.effective_locale', 'en')
            ->assertJsonPath('preferences.personalized_news_enabled', false);
        $this->assertDatabaseMissing('reader_preferences', ['user_id' => $other->id]);
    }

    public function test_invalid_locale_and_history_tracking_cannot_be_enabled(): void
    {
        $user = $this->reader();
        $this->withReaderToken($user)
            ->patchJson('/api/v1/me/preferences', ['preferred_locale' => 'tr'])
            ->assertUnprocessable()->assertJsonValidationErrors('preferred_locale');
        $this->patchJson('/api/v1/me/preferences', ['reading_history_enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('reading_history_enabled');
        $this->patchJson('/api/v1/me/preferences', ['personalized_news_enabled' => 'not-boolean'])
            ->assertUnprocessable()->assertJsonValidationErrors('personalized_news_enabled');
        $this->patchJson('/api/v1/me/preferences', ['user_id' => 999, 'analytics_consent' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('reader_preferences', 0);
    }

    public function test_explicit_locale_can_be_cleared_to_follow_device_again(): void
    {
        $user = $this->reader();
        $this->withReaderToken($user)
            ->patchJson('/api/v1/me/preferences', ['preferred_locale' => 'de'])
            ->assertOk()->assertJsonPath('preferences.effective_locale', 'de');
        $this->withHeader('Accept-Language', 'it-CH,it;q=0.9')
            ->patchJson('/api/v1/me/preferences', ['preferred_locale' => null])
            ->assertOk()
            ->assertJsonPath('preferences.preferred_locale', null)
            ->assertJsonPath('preferences.effective_locale', 'it');
    }

    public function test_token_abilities_enforce_read_write_separation(): void
    {
        $user = $this->reader();
        $this->withReaderToken($user, ['reader:read'])
            ->getJson('/api/v1/me')->assertOk();
        $this->patchJson('/api/v1/me', ['name' => 'Should Fail'])->assertForbidden();
        $this->patchJson('/api/v1/me/preferences', ['analytics_consent' => true])->assertForbidden();
        $this->putJson('/api/v1/me/favorites', ['sport_ids' => [], 'team_ids' => []])->assertForbidden();

        $this->withReaderToken($user, ['reader:write'])
            ->getJson('/api/v1/me')->assertForbidden();
        $this->patchJson('/api/v1/me', ['name' => 'Allowed Write'])->assertOk();
    }

    public function test_new_login_token_receives_reader_read_and_write_without_staff_privileges(): void
    {
        $user = User::factory()->create([
            'email' => 'reader@example.test', 'password' => 'Original!Password2026',
        ]);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Original!Password2026',
        ])->assertOk();

        $token = $user->tokens()->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['reader:read', 'reader:write'], $token->abilities);
        $this->assertFalse($token->can('admin:access'));
        $this->assertFalse($token->can('staff:write'));
    }

    public function test_favorites_use_real_foreign_keys_and_only_owned_relationships(): void
    {
        // Test-only catalog fixtures, not runtime seeds or mock external data.
        $sport = Sport::create(['slug' => 'football', 'name' => 'Football']);
        $team = Team::create(['sport_id' => $sport->id, 'slug' => 'club-one', 'name' => 'Club One']);
        $other = $this->reader();
        $user = $this->reader();
        $this->withReaderToken($user)
            ->putJson('/api/v1/me/favorites', [
                'sport_ids' => [$sport->id], 'team_ids' => [$team->id],
            ])->assertOk()
            ->assertJsonPath('favorites.sports.0.id', $sport->id)
            ->assertJsonPath('favorites.teams.0.id', $team->id)
            ->assertJsonPath('favorites.teams.0.sport_id', $sport->id);

        $this->assertDatabaseHas('reader_favorite_sports', ['user_id' => $user->id, 'sport_id' => $sport->id]);
        $this->assertDatabaseHas('reader_favorite_teams', ['user_id' => $user->id, 'team_id' => $team->id]);

        $this->withReaderToken($other)
            ->getJson('/api/v1/me/favorites')->assertOk()
            ->assertJsonCount(0, 'favorites.teams')
            ->assertJsonCount(0, 'favorites.sports');
        $this->assertDatabaseMissing('reader_favorite_teams', ['user_id' => $other->id, 'team_id' => $team->id]);
    }

    public function test_replacing_favorites_is_atomic_when_invalid_ids_are_supplied(): void
    {
        $sport = Sport::create(['slug' => 'basketball', 'name' => 'Basketball']);
        $user = $this->reader();
        $this->withReaderToken($user)
            ->putJson('/api/v1/me/favorites', ['sport_ids' => [$sport->id], 'team_ids' => []])
            ->assertOk();

        $this->putJson('/api/v1/me/favorites', ['sport_ids' => [999999], 'team_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('sport_ids.0');
        $this->putJson('/api/v1/me/favorites', ['sport_ids' => [$sport->id, $sport->id], 'team_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('sport_ids.1');
        $this->putJson('/api/v1/me/favorites', ['sport_ids' => [], 'team_ids' => [999999]])
            ->assertUnprocessable()->assertJsonValidationErrors('team_ids.0');
        $this->putJson('/api/v1/me/favorites', ['sport_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('team_ids');
        $this->putJson('/api/v1/me/favorites', [
            'sport_ids' => [], 'team_ids' => [], 'user_id' => $user->id + 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('reader_favorite_sports', 1);
        $this->assertDatabaseHas('reader_favorite_sports', ['user_id' => $user->id, 'sport_id' => $sport->id]);
    }

    public function test_favorites_can_be_cleared_explicitly(): void
    {
        $sport = Sport::create(['slug' => 'volleyball', 'name' => 'Volleyball']);
        $user = $this->reader();
        $this->withReaderToken($user)
            ->putJson('/api/v1/me/favorites', ['sport_ids' => [$sport->id], 'team_ids' => []])->assertOk();
        $this->putJson('/api/v1/me/favorites', ['sport_ids' => [], 'team_ids' => []])
            ->assertOk()->assertJsonCount(0, 'favorites.sports');
        $this->assertDatabaseCount('reader_favorite_sports', 0);
    }

    public function test_unverified_account_cannot_self_mint_reader_token_for_preferences(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.test',
            'password' => 'Unverified!Password2026',
        ]);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Unverified!Password2026',
        ])->assertUnauthorized();
        $this->getJson('/api/v1/me/preferences')->assertUnauthorized();
    }
}
