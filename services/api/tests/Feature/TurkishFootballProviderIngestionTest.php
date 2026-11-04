<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sports\FixtureStateMachine;
use App\Domain\Sports\ProviderEntityMapper;
use App\Domain\Sports\Providers\TurkishFootballProviderIngestor;
use App\Models\Season;
use App\Models\Sport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TurkishFootballProviderIngestionTest extends TestCase
{
    use RefreshDatabase;

    private TurkishFootballProviderIngestor $ingestor;
    private ProviderEntityMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ProviderEntityMapper();
        $this->ingestor = new TurkishFootballProviderIngestor($this->mapper, new FixtureStateMachine());
    }

    public function test_ingests_turkish_prioritized_competitions_and_maps_entities(): void
    {
        $superLig = $this->ingestor->ingestTurkishCompetition([
            'slug' => 'super-lig',
            'name' => 'Trendyol Süper Lig',
            'provider_ref' => 'tff_comp_superlig',
        ]);

        $birinciLig = $this->ingestor->ingestTurkishCompetition([
            'slug' => 'trendyol-1-lig',
            'name' => 'Trendyol 1. Lig',
            'provider_ref' => 'tff_comp_1lig',
        ]);

        $turkiyeKupasi = $this->ingestor->ingestTurkishCompetition([
            'slug' => 'turkiye-kupasi',
            'name' => 'Ziraat Türkiye Kupası',
            'provider_ref' => 'tff_comp_tk',
        ]);

        $this->assertEquals('TR', $superLig->country_code);
        $this->assertEquals('Trendyol Süper Lig', $superLig->name);
        $this->assertEquals($superLig->id, $this->mapper->resolveEntityId(TurkishFootballProviderIngestor::PROVIDER_NAME, 'competition', 'tff_comp_superlig'));
        $this->assertEquals($birinciLig->id, $this->mapper->resolveEntityId(TurkishFootballProviderIngestor::PROVIDER_NAME, 'competition', 'tff_comp_1lig'));
        $this->assertEquals($turkiyeKupasi->id, $this->mapper->resolveEntityId(TurkishFootballProviderIngestor::PROVIDER_NAME, 'competition', 'tff_comp_tk'));
    }

    public function test_ingests_turkish_teams_with_stadium_and_colors(): void
    {
        $comp = $this->ingestor->ingestTurkishCompetition([
            'slug' => 'super-lig',
            'name' => 'Trendyol Süper Lig',
            'provider_ref' => 'tff_comp_superlig',
        ]);

        $gala = $this->ingestor->ingestTurkishTeam($comp, [
            'slug' => 'galatasaray',
            'name' => 'Galatasaray A.Ş.',
            'short_name' => 'GS',
            'primary_color' => '#A90432',
            'secondary_color' => '#FDB912',
            'provider_ref' => 'tff_team_gs',
        ]);

        $fener = $this->ingestor->ingestTurkishTeam($comp, [
            'slug' => 'fenerbahce',
            'name' => 'Fenerbahçe S.K.',
            'short_name' => 'FB',
            'primary_color' => '#002D72',
            'secondary_color' => '#FFED00',
            'provider_ref' => 'tff_team_fb',
        ]);

        $this->assertEquals('Galatasaray A.Ş.', $gala->name);
        $this->assertEquals('GS', $gala->short_name);
        $this->assertEquals('#A90432', $gala->primary_color);
        $this->assertEquals($gala->id, $this->mapper->resolveEntityId(TurkishFootballProviderIngestor::PROVIDER_NAME, 'team', 'tff_team_gs'));
        $this->assertEquals($fener->id, $this->mapper->resolveEntityId(TurkishFootballProviderIngestor::PROVIDER_NAME, 'team', 'tff_team_fb'));
    }

    public function test_ingests_fixture_and_tracks_live_revisions_monotonically(): void
    {
        $comp = $this->ingestor->ingestTurkishCompetition([
            'slug' => 'super-lig',
            'name' => 'Trendyol Süper Lig',
            'provider_ref' => 'tff_comp_superlig',
        ]);

        $this->ingestor->ingestTurkishTeam($comp, [
            'slug' => 'galatasaray',
            'name' => 'Galatasaray',
            'provider_ref' => 'tff_team_gs',
        ]);

        $this->ingestor->ingestTurkishTeam($comp, [
            'slug' => 'fenerbahce',
            'name' => 'Fenerbahçe',
            'provider_ref' => 'tff_team_fb',
        ]);

        $season = Season::create([
            'competition_id' => $comp->id,
            'label' => '2026-2027',
            'is_current' => true,
        ]);

        $fixture = $this->ingestor->ingestTurkishFixture($comp, $season, [
            'home_team_ref' => 'tff_team_gs',
            'away_team_ref' => 'tff_team_fb',
            'provider_fixture_id' => 'tff_derby_101',
            'starts_at' => now()->toIso8601String(),
            'status' => 'scheduled',
            'source_revision' => 1,
        ]);

        $this->assertEquals('scheduled', $fixture->status);
        $this->assertEquals(1, $fixture->source_revision);

        // Apply revision 2: Match starts, 1-0 goal
        $resultRev2 = $this->ingestor->ingestLiveRevision($fixture, [
            'status' => 'live',
            'home_score' => 1,
            'away_score' => 0,
            'first_scoring_team' => 'home',
            'source_revision' => 2,
            'events' => [
                [
                    'event_type' => 'goal',
                    'minute' => 24,
                    'player_name' => 'Mauro Icardi',
                    'detail' => 'Penaltı',
                    'team_id' => $fixture->home_team_id,
                ],
            ],
        ]);

        $this->assertTrue($resultRev2['applied']);
        $this->assertEquals('live', $resultRev2['fixture']->status);
        $this->assertEquals(1, $resultRev2['fixture']->home_score);
        $this->assertEquals(2, $resultRev2['fixture']->source_revision);

        // Verify event in DB
        $event = DB::table('fixture_events')->where('fixture_id', $fixture->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('Mauro Icardi', $event->player_name);
        $this->assertEquals(24, $event->minute);

        // Try applying stale revision 1 again (out-of-order delivery protection)
        $resultStale = $this->ingestor->ingestLiveRevision($fixture->fresh(), [
            'status' => 'scheduled',
            'home_score' => 0,
            'away_score' => 0,
            'source_revision' => 1,
        ]);

        $this->assertFalse($resultStale['applied']);
        $this->assertStringContainsString('Stale revision rejected', $resultStale['reason']);
        $this->assertEquals(1, $fixture->fresh()->home_score);
        $this->assertEquals('live', $fixture->fresh()->status);
    }

    public function test_integrates_with_licensed_provider_sandbox_feed(): void
    {
        $provider = new \App\Domain\Sports\Providers\LicensedSportsProviderAdapter();
        $fixtures = $provider->fetchFixtures('tff_superlig');

        $this->assertNotEmpty($fixtures);
        $this->assertEquals('ext-fix-tff_superlig-01', $fixtures[0]['provider_fixture_id']);

        $liveState = $provider->fetchLiveMatchState($fixtures[0]['provider_fixture_id']);
        $this->assertEquals('live', $liveState['status']);
        $this->assertEquals(2, $liveState['home_score']);
        $this->assertEquals(1, $liveState['away_score']);
        $this->assertCount(3, $liveState['events']);
    }
}
