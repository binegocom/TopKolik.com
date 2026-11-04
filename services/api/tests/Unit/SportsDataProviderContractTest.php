<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sports\Providers\LicensedSportsProviderAdapter;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class SportsDataProviderContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_provider_contract_provides_quota_and_name(): void
    {
        $adapter = new LicensedSportsProviderAdapter(dailyQuota: 500);

        $this->assertSame('licensed_sports_feed', $adapter->getProviderName());
        $this->assertSame(500, $adapter->getDailyQuotaLimit());
        $this->assertSame(500, $adapter->getRemainingQuota());
    }

    public function test_fixtures_ingestion_payload_structure(): void
    {
        $adapter = new LicensedSportsProviderAdapter();

        $fixtures = $adapter->fetchFixtures('super-lig');

        $this->assertIsArray($fixtures);
        $this->assertNotEmpty($fixtures);
        $this->assertArrayHasKey('provider_fixture_id', $fixtures[0]);
        $this->assertArrayHasKey('home_team_ref', $fixtures[0]);
        $this->assertArrayHasKey('away_team_ref', $fixtures[0]);
        $this->assertArrayHasKey('starts_at', $fixtures[0]);

        $this->assertSame(9999, $adapter->getRemainingQuota());
    }

    public function test_live_match_state_returns_scores_and_events(): void
    {
        $adapter = new LicensedSportsProviderAdapter();

        $liveState = $adapter->fetchLiveMatchState('fix-derby-01');

        $this->assertSame('live', $liveState['status']);
        $this->assertSame(67, $liveState['minute']);
        $this->assertSame(2, $liveState['home_score']);
        $this->assertSame(1, $liveState['away_score']);
        $this->assertCount(3, $liveState['events']);
    }

    public function test_team_roster_returns_athletes(): void
    {
        $adapter = new LicensedSportsProviderAdapter();

        $roster = $adapter->fetchTeamRoster('team-galatasaray');

        $this->assertSame('team-galatasaray', $roster['provider_team_id']);
        $this->assertNotEmpty($roster['athletes']);
        $this->assertSame('Victor Osimhen', $roster['athletes'][2]['name']);
    }

    public function test_quota_exhaustion_throws_runtime_exception(): void
    {
        $adapter = new LicensedSportsProviderAdapter(dailyQuota: 1);

        $adapter->fetchFixtures('super-lig'); // Consumes 1 call (quota = 0 remaining)

        $this->expectException(RuntimeException::class);
        $adapter->fetchLiveMatchState('fix-02');
    }
}
