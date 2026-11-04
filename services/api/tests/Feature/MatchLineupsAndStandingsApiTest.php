<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MatchLineupsAndStandingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function createMatchFixture(): array
    {
        $sport = Sport::create(['slug' => 'football-'.Str::random(6), 'name' => 'Football']);
        $homeTeam = Team::create(['sport_id' => $sport->id, 'slug' => 'galatasaray-'.Str::random(6), 'name' => 'Galatasaray']);
        $awayTeam = Team::create(['sport_id' => $sport->id, 'slug' => 'fenerbahce-'.Str::random(6), 'name' => 'Fenerbahçe']);

        $now = now();
        $competitionId = DB::table('competitions')->insertGetId([
            'sport_id' => $sport->id,
            'slug' => 'super-lig-'.Str::random(6),
            'name' => 'Trendyol Süper Lig',
            'country_code' => 'TR',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $seasonId = DB::table('seasons')->insertGetId([
            'competition_id' => $competitionId,
            'label' => '2026-2027',
            'starts_on' => $now->toDateString(),
            'ends_on' => $now->copy()->addMonths(9)->toDateString(),
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fixtureId = DB::table('fixtures')->insertGetId([
            'competition_id' => $competitionId,
            'season_id' => $seasonId,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'live',
            'starts_at' => $now->copy()->subMinutes(60),
            'home_score' => 2,
            'away_score' => 1,
            'first_scoring_team' => 'home',
            'source_revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'competitionId' => $competitionId,
            'seasonId' => $seasonId,
            'fixtureId' => $fixtureId,
            'homeTeam' => $homeTeam,
            'awayTeam' => $awayTeam,
        ];
    }

    public function test_lineups_endpoint_returns_starters_and_bench_for_both_teams(): void
    {
        $fixtureData = $this->createMatchFixture();
        $fixtureId = $fixtureData['fixtureId'];
        $homeTeam = $fixtureData['homeTeam'];
        $awayTeam = $fixtureData['awayTeam'];
        $now = now();

        $homeLineupId = DB::table('fixture_lineups')->insertGetId([
            'fixture_id' => $fixtureId,
            'team_id' => $homeTeam->id,
            'formation' => '4-2-3-1',
            'coach_name' => 'Okan Buruk',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $awayLineupId = DB::table('fixture_lineups')->insertGetId([
            'fixture_id' => $fixtureId,
            'team_id' => $awayTeam->id,
            'formation' => '4-3-3',
            'coach_name' => 'José Mourinho',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Insert Home players (starters and bench)
        DB::table('fixture_lineup_players')->insert([
            [
                'fixture_lineup_id' => $homeLineupId,
                'player_name' => 'Fernando Muslera',
                'shirt_number' => 1,
                'position' => 'GK',
                'grid_row' => 1,
                'grid_col' => 3,
                'is_starter' => true,
                'is_captain' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fixture_lineup_id' => $homeLineupId,
                'player_name' => 'Mauro Icardi',
                'shirt_number' => 9,
                'position' => 'FW',
                'grid_row' => 5,
                'grid_col' => 3,
                'is_starter' => true,
                'is_captain' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fixture_lineup_id' => $homeLineupId,
                'player_name' => 'Michy Batshuayi',
                'shirt_number' => 44,
                'position' => 'FW',
                'grid_row' => 1,
                'grid_col' => 1,
                'is_starter' => false,
                'is_captain' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Insert Away players
        DB::table('fixture_lineup_players')->insert([
            [
                'fixture_lineup_id' => $awayLineupId,
                'player_name' => 'Dominik Livaković',
                'shirt_number' => 40,
                'position' => 'GK',
                'grid_row' => 1,
                'grid_col' => 3,
                'is_starter' => true,
                'is_captain' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fixture_lineup_id' => $awayLineupId,
                'player_name' => 'Edin Džeko',
                'shirt_number' => 9,
                'position' => 'FW',
                'grid_row' => 5,
                'grid_col' => 3,
                'is_starter' => true,
                'is_captain' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->getJson("/api/v1/matches/{$fixtureId}/lineups");

        $response->assertOk();
        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));

        $home = $response->json('home');
        $this->assertSame('4-2-3-1', $home['formation']);
        $this->assertSame('Okan Buruk', $home['coach_name']);
        $this->assertCount(2, $home['starters']);
        $this->assertCount(1, $home['bench']);
        $this->assertSame('Fernando Muslera', $home['starters'][0]['player_name']);
        $this->assertTrue($home['starters'][0]['is_captain']);

        $away = $response->json('away');
        $this->assertSame('4-3-3', $away['formation']);
        $this->assertSame('José Mourinho', $away['coach_name']);
        $this->assertCount(2, $away['starters']);
    }

    public function test_standings_endpoint_returns_ranked_table_with_metrics(): void
    {
        $fixtureData = $this->createMatchFixture();
        $competitionId = $fixtureData['competitionId'];
        $seasonId = $fixtureData['seasonId'];
        $homeTeam = $fixtureData['homeTeam'];
        $awayTeam = $fixtureData['awayTeam'];
        $now = now();

        DB::table('competition_standings')->insert([
            [
                'competition_id' => $competitionId,
                'season_id' => $seasonId,
                'team_id' => $homeTeam->id,
                'position' => 1,
                'played' => 10,
                'won' => 9,
                'drawn' => 1,
                'lost' => 0,
                'goals_for' => 28,
                'goals_against' => 8,
                'goal_difference' => 20,
                'points' => 28,
                'form' => 'WWWDW',
                'source_provenance' => 'tff:official:table',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'competition_id' => $competitionId,
                'season_id' => $seasonId,
                'team_id' => $awayTeam->id,
                'position' => 2,
                'played' => 10,
                'won' => 8,
                'drawn' => 2,
                'lost' => 0,
                'goals_for' => 25,
                'goals_against' => 7,
                'goal_difference' => 18,
                'points' => 26,
                'form' => 'WWDWW',
                'source_provenance' => 'tff:official:table',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->getJson("/api/v1/competitions/{$competitionId}/standings");

        $response->assertOk();
        $this->assertStringContainsString('max-age=120', (string) $response->headers->get('Cache-Control'));

        $standings = $response->json('standings');
        $this->assertCount(2, $standings);
        $this->assertSame(1, $standings[0]['position']);
        $this->assertSame('Galatasaray', $standings[0]['team_name']);
        $this->assertSame(28, $standings[0]['points']);
        $this->assertSame(20, $standings[0]['goal_difference']);
        $this->assertSame('WWWDW', $standings[0]['form']);

        $this->assertSame(2, $standings[1]['position']);
        $this->assertSame('Fenerbahçe', $standings[1]['team_name']);
        $this->assertSame(26, $standings[1]['points']);
    }

    public function test_lineups_and_standings_return_404_when_missing(): void
    {
        $this->getJson('/api/v1/matches/999999/lineups')->assertStatus(404);
        $this->getJson('/api/v1/competitions/999999/standings')->assertStatus(404);
    }
}
