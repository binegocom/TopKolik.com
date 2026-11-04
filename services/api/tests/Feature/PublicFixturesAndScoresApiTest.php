<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicFixturesAndScoresApiTest extends TestCase
{
    use RefreshDatabase;

    private function createFixturesFixture(): array
    {
        $sport = Sport::create(['slug' => 'football-'.Str::random(6), 'name' => 'Football']);
        $homeTeam = Team::create([
            'sport_id' => $sport->id,
            'slug' => 'galatasaray-'.Str::random(6),
            'name' => 'Galatasaray',
            'short_code' => 'GS',
        ]);
        $awayTeam = Team::create([
            'sport_id' => $sport->id,
            'slug' => 'fenerbahce-'.Str::random(6),
            'name' => 'Fenerbahçe',
            'short_code' => 'FB',
        ]);

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

        $liveFixtureId = DB::table('fixtures')->insertGetId([
            'competition_id' => $competitionId,
            'season_id' => $seasonId,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'live',
            'starts_at' => $now->copy()->subMinutes(35),
            'home_score' => 2,
            'away_score' => 1,
            'first_scoring_team' => 'home',
            'source_revision' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $scheduledFixtureId = DB::table('fixtures')->insertGetId([
            'competition_id' => $competitionId,
            'season_id' => $seasonId,
            'home_team_id' => $awayTeam->id,
            'away_team_id' => $homeTeam->id,
            'status' => 'scheduled',
            'starts_at' => $now->copy()->addDays(7),
            'home_score' => null,
            'away_score' => null,
            'first_scoring_team' => null,
            'source_revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $arenaId = DB::table('match_arenas')->insertGetId([
            'fixture_id' => $liveFixtureId,
            'state' => 'live_open',
            'posting_enabled' => true,
            'slow_mode_seconds' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'competitionId' => $competitionId,
            'seasonId' => $seasonId,
            'homeTeam' => $homeTeam,
            'awayTeam' => $awayTeam,
            'liveFixtureId' => $liveFixtureId,
            'scheduledFixtureId' => $scheduledFixtureId,
            'arenaId' => $arenaId,
        ];
    }

    public function test_can_list_fixtures_publicly_with_deterministic_cache_headers(): void
    {
        $ctx = $this->createFixturesFixture();

        $response = $this->getJson('/api/v1/matches');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'competition_id',
                        'competition_name',
                        'home_team_name',
                        'away_team_name',
                        'status',
                        'starts_at',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=120');

        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_filter_fixtures_by_live_status_with_short_cache_ttl(): void
    {
        $ctx = $this->createFixturesFixture();

        $response = $this->getJson('/api/v1/matches?status=live');
        $response->assertOk()
            ->assertHeader('Cache-Control', 'public, max-age=15, stale-while-revalidate=15')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ctx['liveFixtureId'])
            ->assertJsonPath('data.0.status', 'live')
            ->assertJsonPath('data.0.home_score', 2)
            ->assertJsonPath('data.0.away_score', 1);
    }

    public function test_rejects_invalid_fixture_status(): void
    {
        $this->getJson('/api/v1/matches?status=invalid_status')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_can_retrieve_single_fixture_detail(): void
    {
        $ctx = $this->createFixturesFixture();

        $response = $this->getJson('/api/v1/matches/'.$ctx['liveFixtureId']);
        $response->assertOk()
            ->assertHeader('Cache-Control', 'public, max-age=15, stale-while-revalidate=120')
            ->assertJsonPath('fixture.id', $ctx['liveFixtureId'])
            ->assertJsonPath('fixture.home_team_name', 'Galatasaray')
            ->assertJsonPath('fixture.away_team_name', 'Fenerbahçe')
            ->assertJsonPath('fixture.home_score', 2)
            ->assertJsonPath('fixture.away_score', 1)
            ->assertJsonPath('fixture.arena_state', 'live_open')
            ->assertJsonPath('fixture.arena_posting_enabled', true)
            ->assertJsonPath('fixture.arena_slow_mode_seconds', 10);
    }

    public function test_returns_404_for_unknown_fixture(): void
    {
        $this->getJson('/api/v1/matches/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Fixture not found.');
    }
}
