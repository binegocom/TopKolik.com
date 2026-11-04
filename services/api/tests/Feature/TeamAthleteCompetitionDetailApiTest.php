<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\AthleteSeasonStatistic;
use App\Models\Competition;
use App\Models\CompetitionStanding;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Sport;
use App\Models\Team;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamAthleteCompetitionDetailApiTest extends TestCase
{
    use RefreshDatabase;

    private Sport $football;
    private Venue $ramspark;
    private Team $galatasaray;
    private Team $fenerbahce;
    private Competition $superLig;
    private Season $currentSeason;
    private Athlete $osimhen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->football = Sport::create(['slug' => 'futbol', 'name' => 'Futbol']);

        $this->ramspark = Venue::create([
            'slug' => 'rams-park',
            'name' => 'RAMS Park',
            'city' => 'İstanbul',
            'country_code' => 'TR',
            'capacity' => 52280,
            'surface' => 'grass',
        ]);

        $this->galatasaray = Team::create([
            'sport_id' => $this->football->id,
            'slug' => 'galatasaray',
            'name' => 'Galatasaray',
            'short_name' => 'GS',
            'venue_id' => $this->ramspark->id,
            'primary_color' => '#A90432',
            'secondary_color' => '#FDB912',
        ]);

        $this->fenerbahce = Team::create([
            'sport_id' => $this->football->id,
            'slug' => 'fenerbahce',
            'name' => 'Fenerbahçe',
            'short_name' => 'FB',
            'primary_color' => '#002D72',
            'secondary_color' => '#FFED00',
        ]);

        $this->superLig = Competition::create([
            'sport_id' => $this->football->id,
            'slug' => 'super-lig',
            'name' => 'Trendyol Süper Lig',
            'country_code' => 'TR',
        ]);

        $this->currentSeason = Season::create([
            'competition_id' => $this->superLig->id,
            'label' => '2026-2027',
            'starts_on' => '2026-08-10',
            'ends_on' => '2027-05-25',
            'is_current' => true,
        ]);

        $this->osimhen = Athlete::create([
            'sport_id' => $this->football->id,
            'current_team_id' => $this->galatasaray->id,
            'slug' => 'victor-osimhen',
            'name' => 'Victor Osimhen',
            'first_name' => 'Victor',
            'last_name' => 'Osimhen',
            'nationality' => 'Nigeria',
            'country_code' => 'NG',
            'position' => 'FW',
            'shirt_number' => 45,
            'birth_date' => '1998-12-29',
            'height_cm' => 186,
            'weight_kg' => 78,
            'preferred_foot' => 'right',
        ]);

        AthleteSeasonStatistic::create([
            'athlete_id' => $this->osimhen->id,
            'season_id' => $this->currentSeason->id,
            'team_id' => $this->galatasaray->id,
            'appearances' => 10,
            'minutes_played' => 890,
            'goals' => 8,
            'assists' => 3,
            'yellow_cards' => 1,
            'red_cards' => 0,
            'rating' => 8.15,
        ]);

        CompetitionStanding::create([
            'competition_id' => $this->superLig->id,
            'season_id' => $this->currentSeason->id,
            'team_id' => $this->galatasaray->id,
            'position' => 1,
            'played' => 10,
            'won' => 9,
            'drawn' => 1,
            'lost' => 0,
            'goals_for' => 28,
            'goals_against' => 8,
            'goal_difference' => 20,
            'points' => 28,
            'form' => 'W,W,W,D,W',
        ]);

        Fixture::create([
            'competition_id' => $this->superLig->id,
            'season_id' => $this->currentSeason->id,
            'home_team_id' => $this->galatasaray->id,
            'away_team_id' => $this->fenerbahce->id,
            'status' => 'finished',
            'starts_at' => now()->subDays(2),
            'home_score' => 3,
            'away_score' => 1,
            'first_scoring_team' => 'home',
            'source_revision' => 1,
        ]);
    }

    public function test_team_detail_by_slug_returns_squad_venue_and_fixtures(): void
    {
        $response = $this->getJson('/api/v1/teams/galatasaray');

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control');
        $response->assertHeader('ETag');

        $response->assertJsonPath('team.name', 'Galatasaray');
        $response->assertJsonPath('team.slug', 'galatasaray');
        $response->assertJsonPath('team.venue.name', 'RAMS Park');
        $response->assertJsonPath('team.venue.capacity', 52280);
        $response->assertJsonCount(1, 'squad');
        $response->assertJsonPath('squad.0.name', 'Victor Osimhen');
        $response->assertJsonPath('squad.0.shirt_number', 45);
        $response->assertJsonCount(1, 'recent_fixtures');
        $response->assertJsonPath('recent_fixtures.0.result', 'W');
        $response->assertJsonPath('recent_fixtures.0.home_score', 3);
        $response->assertJsonPath('recent_fixtures.0.away_score', 1);
        $response->assertJsonPath('standing.position', 1);
        $response->assertJsonPath('standing.points', 28);
    }

    public function test_team_detail_by_id_and_etag_conditional_revalidation(): void
    {
        $response = $this->getJson("/api/v1/teams/{$this->galatasaray->id}");
        $response->assertStatus(200);
        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);

        // Test 304 Not Modified
        $conditional = $this->withHeader('If-None-Match', $etag)
            ->getJson("/api/v1/teams/{$this->galatasaray->id}");

        $conditional->assertStatus(304);
    }

    public function test_athlete_detail_returns_profile_and_stats(): void
    {
        $response = $this->getJson('/api/v1/athletes/victor-osimhen');

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control');
        $response->assertHeader('ETag');

        $response->assertJsonPath('athlete.name', 'Victor Osimhen');
        $response->assertJsonPath('athlete.position', 'FW');
        $response->assertJsonPath('athlete.shirt_number', 45);
        $response->assertJsonPath('athlete.nationality', 'Nigeria');
        $response->assertJsonPath('athlete.current_team.name', 'Galatasaray');
        $response->assertJsonCount(1, 'season_statistics');
        $response->assertJsonPath('season_statistics.0.goals', 8);
        $response->assertJsonPath('season_statistics.0.assists', 3);
        $response->assertJsonPath('season_statistics.0.rating', 8.15);
    }

    public function test_competition_detail_returns_standings_and_schedule(): void
    {
        $response = $this->getJson('/api/v1/competitions/super-lig');

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control');
        $response->assertHeader('ETag');

        $response->assertJsonPath('competition.name', 'Trendyol Süper Lig');
        $response->assertJsonPath('season.label', '2026-2027');
        $response->assertJsonCount(1, 'standings');
        $response->assertJsonPath('standings.0.team.name', 'Galatasaray');
        $response->assertJsonPath('standings.0.points', 28);
        $response->assertJsonCount(1, 'recent_fixtures');
    }

    public function test_not_found_returns_404_for_unknown_entities(): void
    {
        $this->getJson('/api/v1/teams/non-existent-team')->assertStatus(404);
        $this->getJson('/api/v1/athletes/non-existent-athlete')->assertStatus(404);
        $this->getJson('/api/v1/competitions/non-existent-comp')->assertStatus(404);
    }
}

