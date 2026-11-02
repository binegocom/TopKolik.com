<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReaderConsentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/me/privacy/consent-history';

    private function reader(): User
    {
        return User::factory()->create();
    }

    private function asReader(
        User $reader,
        array $abilities = ['reader:read', 'reader:write'],
        int $minutes = 30,
    ): void {
        $token = $reader->createToken('reader-consent-history', $abilities, now()->addMinutes($minutes))
            ->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($token);
    }

    private function consent(string $key, bool $value): void
    {
        $this->patchJson('/api/v1/me/preferences', [$key => $value])->assertOk();
    }

    public function test_default_opt_out_does_not_generate_history_or_mutate_state(): void
    {
        $reader = $this->reader();
        $this->asReader($reader);
        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('consent_history', [])
            ->assertJsonPath('next_before_id', null);

        $this->assertDatabaseCount('reader_consent_events', 0);
        $this->assertDatabaseCount('security_audit_events', 0);
    }

    public function test_history_lists_only_real_transitions_in_descending_order_without_identity_fields(): void
    {
        $owner = $this->reader();
        $other = $this->reader();
        $this->asReader($owner);
        $this->consent('analytics_consent', true);
        $this->consent('analytics_consent', true);
        $this->consent('personalized_news_enabled', true);
        $this->consent('analytics_consent', false);
        $this->asReader($other);
        $this->consent('analytics_consent', true);

        $before = DB::table('reader_consent_events')->count();
        $this->asReader($owner);
        $response = $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonCount(3, 'consent_history')
            ->assertJsonPath('consent_history.0.consent_key', 'analytics_consent')
            ->assertJsonPath('consent_history.0.granted', false)
            ->assertJsonPath('consent_history.1.consent_key', 'personalized_news_enabled')
            ->assertJsonPath('consent_history.1.granted', true)
            ->assertJsonPath('consent_history.2.consent_key', 'analytics_consent')
            ->assertJsonPath('consent_history.2.granted', true)
            ->assertJsonPath('next_before_id', null)
            ->assertJsonMissingPath('consent_history.0.user_id')
            ->assertJsonMissingPath('consent_history.0.email')
            ->assertJsonMissingPath('consent_history.0.password');

        $events = $response->json('consent_history');
        $this->assertGreaterThan($events[1]['id'], $events[0]['id']);
        $this->assertGreaterThan($events[2]['id'], $events[1]['id']);
        $this->assertNotEmpty($events[0]['recorded_at']);
        $this->assertStringNotContainsString($other->email, $response->getContent());
        $this->assertDatabaseCount('reader_consent_events', $before);
    }

    public function test_keyset_pagination_and_consent_key_filter_stay_scoped_to_current_reader(): void
    {
        $owner = $this->reader();
        $other = $this->reader();
        $this->asReader($owner);
        $this->consent('analytics_consent', true);
        $this->consent('personalized_news_enabled', true);
        $this->consent('analytics_consent', false);
        $this->consent('analytics_consent', true);

        $this->asReader($other);
        $this->consent('analytics_consent', true);
        $this->asReader($owner);

        $first = $this->getJson(self::ENDPOINT.'?consent_key=analytics_consent&limit=1')
            ->assertOk()->assertJsonCount(1, 'consent_history');
        $firstId = (int) $first->json('consent_history.0.id');
        $this->assertSame($firstId, $first->json('next_before_id'));

        $second = $this->getJson(
            self::ENDPOINT.'?consent_key=analytics_consent&limit=1&before_id='.$firstId,
        )->assertOk()->assertJsonCount(1, 'consent_history');
        $secondId = (int) $second->json('consent_history.0.id');
        $this->assertLessThan($firstId, $secondId);
        $this->assertSame($secondId, $second->json('next_before_id'));

        $third = $this->getJson(
            self::ENDPOINT.'?consent_key=analytics_consent&limit=1&before_id='.$secondId,
        )->assertOk()->assertJsonCount(1, 'consent_history')
            ->assertJsonPath('next_before_id', null);
        $this->assertLessThan($secondId, (int) $third->json('consent_history.0.id'));

        $this->getJson(self::ENDPOINT.'?consent_key=personalized_news_enabled')
            ->assertOk()->assertJsonCount(1, 'consent_history');
    }

    public function test_invalid_cursors_filters_and_attempted_actor_selectors_are_rejected(): void
    {
        $this->asReader($this->reader());
        foreach ([
            'limit=0' => 'limit',
            'limit=51' => 'limit',
            'limit=non-numeric' => 'limit',
            'before_id=0' => 'before_id',
            'before_id=-1' => 'before_id',
            'consent_key=unknown' => 'consent_key',
            'user_id=123' => 'query',
            'actor_id=123' => 'query',
            'email=someone%40example.test' => 'query',
            'include_all=true' => 'query',
            'sort=asc' => 'query',
        ] as $query => $errorField) {
            $this->getJson(self::ENDPOINT.'?'.$query)
                ->assertUnprocessable()->assertJsonValidationErrors($errorField);
        }
        $this->assertDatabaseCount('reader_consent_events', 0);
    }

    public function test_authentication_scope_expiry_revocation_and_email_verification_fail_closed(): void
    {
        $reader = $this->reader();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        $this->asReader($reader, ['reader:write']);
        $this->getJson(self::ENDPOINT)->assertForbidden();

        $this->asReader($reader, minutes: -1);
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        $this->asReader($reader);
        $this->getJson(self::ENDPOINT)->assertOk();
        $reader->tokens()->delete();
        app('auth')->forgetGuards();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        $this->asReader($reader);
        $reader->forceFill(['email_verified_at' => null])->save();
        app('auth')->forgetGuards();
        $this->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_staff_and_advertiser_bearers_cannot_read_reader_consent_history(): void
    {
        $reader = $this->reader();
        $this->asReader($reader);
        $this->consent('analytics_consent', true);

        $staff = StaffMember::create([
            'name' => 'Staff Privacy Probe',
            'email' => Str::uuid().'@example.test',
            'role' => 'support',
            'password' => 'Secure!PrivacyProbe2026',
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();
        $advertiser = AdvertiserAccount::create([
            'name' => 'Advertiser Privacy Probe',
            'email' => Str::uuid().'@example.test',
            'password' => 'Secure!PrivacyProbe2026',
        ]);

        foreach ([$staff, $advertiser] as $principal) {
            $token = $principal->createToken(
                'cross-principal-privacy', ['reader:read'], now()->addMinutes(30),
            )->plainTextToken;
            app('auth')->forgetGuards();
            $this->withToken($token);
            $this->getJson(self::ENDPOINT)->assertForbidden();
        }
        $this->assertDatabaseCount('reader_consent_events', 1);
    }
}
