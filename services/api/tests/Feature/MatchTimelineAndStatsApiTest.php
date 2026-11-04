<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MatchTimelineAndStatsApiTest extends TestCase
{
    use RefreshDatabase;

    private function createMatchFixture(string $status = 'live'): array
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
            'status' => $status,
            'starts_at' => $now->copy()->subMinutes(60),
            'home_score' => 2,
            'away_score' => 1,
            'first_scoring_team' => 'home',
            'source_revision' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'fixtureId' => $fixtureId,
            'homeTeam' => $homeTeam,
            'awayTeam' => $awayTeam,
        ];
    }

    public function test_timeline_endpoint_returns_events_in_chronological_order(): void
    {
        $fixtureData = $this->createMatchFixture('live');
        $fixtureId = $fixtureData['fixtureId'];
        $homeTeam = $fixtureData['homeTeam'];
        $awayTeam = $fixtureData['awayTeam'];
        $now = now();

        // Insert events out of chronological order
        DB::table('fixture_events')->insert([
            [
                'fixture_id' => $fixtureId,
                'team_id' => $awayTeam->id,
                'player_name' => 'Edin Džeko',
                'minute' => 54,
                'extra_minute' => null,
                'period' => '2h',
                'event_type' => 'goal',
                'detail' => 'Kafa vuruşu',
                'is_var_cancelled' => false,
                'source_provenance' => 'tff:official',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fixture_id' => $fixtureId,
                'team_id' => $homeTeam->id,
                'player_name' => 'Mauro Icardi',
                'minute' => 23,
                'extra_minute' => null,
                'period' => '1h',
                'event_type' => 'goal',
                'detail' => 'Sağ ayak plase',
                'is_var_cancelled' => false,
                'source_provenance' => 'tff:official',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fixture_id' => $fixtureId,
                'team_id' => $homeTeam->id,
                'player_name' => 'Lucas Torreira',
                'minute' => 38,
                'extra_minute' => null,
                'period' => '1h',
                'event_type' => 'yellow_card',
                'detail' => 'Taktik faul',
                'is_var_cancelled' => false,
                'source_provenance' => 'tff:official',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fixture_id' => $fixtureId,
                'team_id' => $awayTeam->id,
                'player_name' => 'Dušan Tadić',
                'minute' => 45,
                'extra_minute' => 2,
                'period' => '1h',
                'event_type' => 'var_decision',
                'detail' => 'VAR: Penaltı İptal Edildi (Ofsayt)',
                'is_var_cancelled' => true,
                'source_provenance' => 'tff:var_review',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->getJson("/api/v1/matches/{$fixtureId}/timeline");

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=15', $cacheControl);

        $events = $response->json('events');
        $this->assertCount(4, $events);

        // Verification of chronological ordering
        $this->assertSame(23, $events[0]['minute']);
        $this->assertSame('Mauro Icardi', $events[0]['player_name']);
        $this->assertSame('goal', $events[0]['event_type']);
        $this->assertFalse($events[0]['is_var_cancelled']);

        $this->assertSame(38, $events[1]['minute']);
        $this->assertSame('yellow_card', $events[1]['event_type']);

        $this->assertSame(45, $events[2]['minute']);
        $this->assertSame(2, $events[2]['extra_minute']);
        $this->assertSame('var_decision', $events[2]['event_type']);
        $this->assertTrue($events[2]['is_var_cancelled']);

        $this->assertSame(54, $events[3]['minute']);
        $this->assertSame('Edin Džeko', $events[3]['player_name']);
    }

    public function test_stats_endpoint_returns_comparative_team_metrics(): void
    {
        $fixtureData = $this->createMatchFixture('live');
        $fixtureId = $fixtureData['fixtureId'];
        $homeTeam = $fixtureData['homeTeam'];
        $awayTeam = $fixtureData['awayTeam'];
        $now = now();

        DB::table('fixture_team_statistics')->insert([
            [
                'fixture_id' => $fixtureId,
                'team_id' => $homeTeam->id,
                'possession_pct' => 58.2,
                'total_shots' => 14,
                'shots_on_target' => 6,
                'shots_off_target' => 5,
                'blocked_shots' => 3,
                'corner_kicks' => 7,
                'offsides' => 2,
                'fouls' => 11,
                'yellow_cards' => 2,
                'red_cards' => 0,
                'passes_completed' => 432,
                'passes_total' => 510,
                'pass_accuracy_pct' => 84.7,
                'expected_goals_xg' => 1.94,
                'source_revision' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fixture_id' => $fixtureId,
                'team_id' => $awayTeam->id,
                'possession_pct' => 41.8,
                'total_shots' => 9,
                'shots_on_target' => 4,
                'shots_off_target' => 3,
                'blocked_shots' => 2,
                'corner_kicks' => 4,
                'offsides' => 3,
                'fouls' => 15,
                'yellow_cards' => 3,
                'red_cards' => 0,
                'passes_completed' => 312,
                'passes_total' => 388,
                'pass_accuracy_pct' => 80.4,
                'expected_goals_xg' => 1.12,
                'source_revision' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->getJson("/api/v1/matches/{$fixtureId}/stats");

        $response->assertOk();
        $this->assertStringContainsString('max-age=15', (string) $response->headers->get('Cache-Control'));

        $data = $response->json();
        $this->assertSame(58.2, (float) $data['home']['possession_pct']);
        $this->assertSame(14, $data['home']['total_shots']);
        $this->assertSame(6, $data['home']['shots_on_target']);
        $this->assertSame(1.94, (float) $data['home']['expected_goals_xg']);

        $this->assertSame(41.8, (float) $data['away']['possession_pct']);
        $this->assertSame(9, $data['away']['total_shots']);
        $this->assertSame(1.12, (float) $data['away']['expected_goals_xg']);
    }

    public function test_timeline_and_stats_return_404_when_fixture_missing(): void
    {
        $this->getJson('/api/v1/matches/999999/timeline')->assertStatus(404);
        $this->getJson('/api/v1/matches/999999/stats')->assertStatus(404);
    }
}

