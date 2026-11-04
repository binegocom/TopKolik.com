<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sports\SportsEntitlementGuard;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SportsEntitlementGuardTest extends TestCase
{
    private SportsEntitlementGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new SportsEntitlementGuard();
    }

    public function test_authorizes_licensed_football_competitions(): void
    {
        $auth = $this->guard->authorizeIngestion('futbol', 'tff_licensed_feed', 'super-lig');

        $this->assertTrue($auth['authorized']);
        $this->assertEquals('futbol', $auth['sport']);
        $this->assertEquals('tier_1', $auth['tier']);
        $this->assertContains('live_score', $auth['capabilities']);
        $this->assertContains('var_events', $auth['capabilities']);
    }

    public function test_authorizes_licensed_basketball_and_volleyball_competitions(): void
    {
        $bkbAuth = $this->guard->authorizeIngestion('basketbol', 'licensed_multisport_feed', 'bsl');
        $this->assertTrue($bkbAuth['authorized']);
        $this->assertEquals('tier_1', $bkbAuth['tier']);
        $this->assertContains('quarter_scores', $bkbAuth['capabilities']);

        $vblAuth = $this->guard->authorizeIngestion('voleybol', 'tvf_official_feed', 'sultanlar-ligi');
        $this->assertTrue($vblAuth['authorized']);
        $this->assertEquals('tier_1', $vblAuth['tier']);
        $this->assertContains('set_scores', $vblAuth['capabilities']);
    }

    public function test_rejects_unlicensed_sport_and_triggers_alert(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('single')
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[SPORTS_ENTITLEMENT_ALERT] unentitled_sport')
                    && ($context['sport'] ?? null) === 'cricket';
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Sport 'cricket' is not entitled for licensed ingestion.");

        $this->guard->authorizeIngestion('cricket', 'random_feed', 'ipl');
    }

    public function test_rejects_unauthorized_provider_for_licensed_sport(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('single')
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[SPORTS_ENTITLEMENT_ALERT] unauthorized_provider')
                    && ($context['provider'] ?? null) === 'pirate_scraper';
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Provider 'pirate_scraper' is not authorized for sport 'futbol'.");

        $this->guard->authorizeIngestion('futbol', 'pirate_scraper', 'super-lig');
    }

    public function test_rejects_unentitled_competition_under_valid_sport(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('single')
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[SPORTS_ENTITLEMENT_ALERT] unentitled_competition')
                    && ($context['competition'] ?? null) === 'amateur-sunday-league';
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Competition 'amateur-sunday-league' is not entitled under sport 'futbol'.");

        $this->guard->authorizeIngestion('futbol', 'tff_licensed_feed', 'amateur-sunday-league');
    }

    public function test_verifies_capability_entitlements(): void
    {
        $this->assertTrue($this->guard->isCapabilityEntitled('futbol', 'var_events'));
        $this->assertTrue($this->guard->isCapabilityEntitled('basketbol', 'quarter_scores'));
        $this->assertTrue($this->guard->isCapabilityEntitled('voleybol', 'attack_efficiency'));
        $this->assertFalse($this->guard->isCapabilityEntitled('futbol', 'quarter_scores'));
        $this->assertFalse($this->guard->isCapabilityEntitled('basketbol', 'var_events'));
    }
}
