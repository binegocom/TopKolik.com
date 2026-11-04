<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sports\FixtureStateMachine;
use App\Domain\Sports\ProviderEntityMapper;
use App\Domain\Sports\Providers\MultiSportProviderIngestor;
use App\Domain\Sports\SportsTaxonomyService;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MultiSportProviderIngestionTest extends TestCase
{
    use RefreshDatabase;

    private MultiSportProviderIngestor $ingestor;
    private ProviderEntityMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ProviderEntityMapper();
        $this->ingestor = new MultiSportProviderIngestor(
            $this->mapper,
            new SportsTaxonomyService(),
            new FixtureStateMachine()
        );
    }

    public function test_ingests_basketball_competition_and_verifies_capabilities(): void
    {
        $result = $this->ingestor->ingestCompetition('basketbol', [
            'slug' => 'bsl',
            'name' => 'Türkiye Sigorta Basketbol Süper Ligi',
            'provider_ref' => 'bkb_comp_bsl',
            'country_code' => 'TR',
        ]);

        $comp = $result['competition'];
        $this->assertEquals('TR', $comp->country_code);
        $this->assertEquals('tier_1', $result['taxonomy']['resolved_tier']);
        $this->assertEquals('points', $result['taxonomy']['scoring_unit']);
        $this->assertContains('quarter_scores', $result['capabilities']);
        $this->assertContains('box_score', $result['capabilities']);

        // Ingest teams
        $efes = $this->ingestor->ingestTeam($comp, [
            'slug' => 'anadolu-efes',
            'name' => 'Anadolu Efes S.K.',
            'provider_ref' => 'bkb_team_efes',
        ]);

        $fbBeko = $this->ingestor->ingestTeam($comp, [
            'slug' => 'fenerbahce-beko',
            'name' => 'Fenerbahçe Beko',
            'provider_ref' => 'bkb_team_fbbeko',
        ]);

        $season = Season::create([
            'competition_id' => $comp->id,
            'label' => '2026-2027',
            'is_current' => true,
        ]);

        $fixture = $this->ingestor->ingestBasketballFixture($comp, $season, [
            'home_team_ref' => 'bkb_team_efes',
            'away_team_ref' => 'bkb_team_fbbeko',
            'provider_fixture_id' => 'bkb_fix_derby_01',
            'starts_at' => now()->toIso8601String(),
            'status' => 'live',
            'home_score' => 84,
            'away_score' => 81,
            'source_revision' => 4,
        ]);

        $this->assertEquals(84, $fixture->home_score);
        $this->assertEquals(81, $fixture->away_score);
        $this->assertEquals('live', $fixture->status);
    }

    public function test_ingests_volleyball_competition_and_verifies_set_capabilities(): void
    {
        $result = $this->ingestor->ingestCompetition('voleybol', [
            'slug' => 'sultanlar-ligi',
            'name' => 'Vodafone Sultanlar Ligi',
            'provider_ref' => 'vbl_comp_sultanlar',
            'country_code' => 'TR',
        ]);

        $comp = $result['competition'];
        $this->assertEquals('tier_1', $result['taxonomy']['resolved_tier']);
        $this->assertEquals('sets_and_points', $result['taxonomy']['scoring_unit']);
        $this->assertContains('set_scores', $result['capabilities']);
        $this->assertContains('attack_efficiency', $result['capabilities']);

        // Ingest teams
        $vakif = $this->ingestor->ingestTeam($comp, [
            'slug' => 'vakifbank',
            'name' => 'VakıfBank S.K.',
            'provider_ref' => 'vbl_team_vakif',
        ]);

        $eczaci = $this->ingestor->ingestTeam($comp, [
            'slug' => 'eczacibasi',
            'name' => 'Eczacıbaşı Dynavit',
            'provider_ref' => 'vbl_team_eczaci',
        ]);

        $season = Season::create([
            'competition_id' => $comp->id,
            'label' => '2026-2027',
            'is_current' => true,
        ]);

        $fixture = $this->ingestor->ingestVolleyballFixture($comp, $season, [
            'home_team_ref' => 'vbl_team_vakif',
            'away_team_ref' => 'vbl_team_eczaci',
            'provider_fixture_id' => 'vbl_fix_final_01',
            'starts_at' => now()->toIso8601String(),
            'status' => 'finished',
            'home_score' => 3,
            'away_score' => 2,
            'source_revision' => 10,
        ]);

        $this->assertEquals(3, $fixture->home_score);
        $this->assertEquals(2, $fixture->away_score);
        $this->assertEquals('finished', $fixture->status);
    }

    public function test_rejects_unsupported_capabilities_for_sport(): void
    {
        $this->assertTrue($this->ingestor->assertSportSupportsCapability('basketbol', 'quarter_scores'));
        $this->assertTrue($this->ingestor->assertSportSupportsCapability('voleybol', 'set_scores'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sport basketbol does not support capability: xG');
        $this->ingestor->assertSportSupportsCapability('basketbol', 'xG');
    }
}
