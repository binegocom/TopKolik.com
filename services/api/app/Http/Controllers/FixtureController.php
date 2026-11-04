<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class FixtureController extends Controller
{
    private const ALLOWED_STATUSES = ['scheduled', 'live', 'finished', 'postponed', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'competition_id' => ['sometimes', 'integer', 'exists:competitions,id'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $query = DB::table('fixtures as f')
            ->join('competitions as c', 'c.id', '=', 'f.competition_id')
            ->join('seasons as s', 's.id', '=', 'f.season_id')
            ->join('teams as ht', 'ht.id', '=', 'f.home_team_id')
            ->join('teams as at', 'at.id', '=', 'f.away_team_id')
            ->select([
                'f.id',
                'f.competition_id',
                'c.name as competition_name',
                'c.slug as competition_slug',
                'f.season_id',
                's.label as season_label',
                'f.home_team_id',
                'ht.name as home_team_name',
                'ht.slug as home_team_slug',
                'f.away_team_id',
                'at.name as away_team_name',
                'at.slug as away_team_slug',
                'f.status',
                'f.starts_at',
                'f.home_score',
                'f.away_score',
                'f.first_scoring_team',
                'f.created_at',
                'f.updated_at',
            ]);

        if (isset($validated['status'])) {
            $query->where('f.status', $validated['status']);
        }

        if (isset($validated['competition_id'])) {
            $query->where('f.competition_id', $validated['competition_id']);
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->orderBy('f.starts_at', 'asc')->paginate($perPage);

        $status = $validated['status'] ?? null;
        $maxAge = ($status === 'live') ? 15 : 60;
        $staleWhileRevalidate = ($status === 'live') ? 15 : 120;

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200, [
            'Cache-Control' => "public, max-age={$maxAge}, stale-while-revalidate={$staleWhileRevalidate}",
        ]);
    }

    public function show(int $fixtureId): JsonResponse
    {
        $fixture = DB::table('fixtures as f')
            ->join('competitions as c', 'c.id', '=', 'f.competition_id')
            ->join('seasons as s', 's.id', '=', 'f.season_id')
            ->join('teams as ht', 'ht.id', '=', 'f.home_team_id')
            ->join('teams as at', 'at.id', '=', 'f.away_team_id')
            ->leftJoin('match_arenas as a', 'a.fixture_id', '=', 'f.id')
            ->where('f.id', $fixtureId)
            ->select([
                'f.id',
                'f.competition_id',
                'c.name as competition_name',
                'c.slug as competition_slug',
                'f.season_id',
                's.label as season_label',
                'f.home_team_id',
                'ht.name as home_team_name',
                'ht.slug as home_team_slug',
                'f.away_team_id',
                'at.name as away_team_name',
                'at.slug as away_team_slug',
                'f.status',
                'f.starts_at',
                'f.home_score',
                'f.away_score',
                'f.first_scoring_team',
                'a.id as arena_id',
                'a.state as arena_state',
                'a.posting_enabled as arena_posting_enabled',
                'a.slow_mode_seconds as arena_slow_mode_seconds',
                'f.created_at',
                'f.updated_at',
            ])
            ->first();

        if (! $fixture) {
            return response()->json(['message' => 'Fixture not found.'], 404);
        }

        $maxAge = ($fixture->status === 'live') ? 15 : 60;

        return response()->json([
            'fixture' => $fixture,
        ], 200, [
            'Cache-Control' => "public, max-age={$maxAge}, stale-while-revalidate=120",
        ]);
    }

    public function timeline(int $fixtureId): JsonResponse
    {
        $fixture = DB::table('fixtures')->where('id', $fixtureId)->first();
        if (! $fixture) {
            return response()->json(['message' => 'Fixture not found.'], 404);
        }

        $events = DB::table('fixture_events as fe')
            ->leftJoin('teams as t', 't.id', '=', 'fe.team_id')
            ->where('fe.fixture_id', $fixtureId)
            ->select([
                'fe.id',
                'fe.fixture_id',
                'fe.team_id',
                't.name as team_name',
                't.slug as team_slug',
                'fe.player_name',
                'fe.minute',
                'fe.extra_minute',
                'fe.period',
                'fe.event_type',
                'fe.detail',
                'fe.is_var_cancelled',
                'fe.source_provenance',
                'fe.created_at',
            ])
            ->orderBy('fe.minute', 'asc')
            ->orderBy('fe.extra_minute', 'asc')
            ->orderBy('fe.id', 'asc')
            ->get();

        $maxAge = ($fixture->status === 'live') ? 15 : 300;
        $staleWhileRevalidate = ($fixture->status === 'live') ? 15 : 600;

        return response()->json([
            'fixture_id' => $fixtureId,
            'status' => $fixture->status,
            'events' => $events,
        ], 200, [
            'Cache-Control' => "public, max-age={$maxAge}, stale-while-revalidate={$staleWhileRevalidate}",
        ]);
    }

    public function stats(int $fixtureId): JsonResponse
    {
        $fixture = DB::table('fixtures')->where('id', $fixtureId)->first();
        if (! $fixture) {
            return response()->json(['message' => 'Fixture not found.'], 404);
        }

        $stats = DB::table('fixture_team_statistics as fts')
            ->join('teams as t', 't.id', '=', 'fts.team_id')
            ->where('fts.fixture_id', $fixtureId)
            ->select([
                'fts.team_id',
                't.name as team_name',
                't.slug as team_slug',
                'fts.possession_pct',
                'fts.total_shots',
                'fts.shots_on_target',
                'fts.shots_off_target',
                'fts.blocked_shots',
                'fts.corner_kicks',
                'fts.offsides',
                'fts.fouls',
                'fts.yellow_cards',
                'fts.red_cards',
                'fts.passes_completed',
                'fts.passes_total',
                'fts.pass_accuracy_pct',
                'fts.expected_goals_xg',
                'fts.source_revision',
            ])
            ->get();

        $homeStats = $stats->firstWhere('team_id', $fixture->home_team_id);
        $awayStats = $stats->firstWhere('team_id', $fixture->away_team_id);

        $maxAge = ($fixture->status === 'live') ? 15 : 300;

        return response()->json([
            'fixture_id' => $fixtureId,
            'status' => $fixture->status,
            'home' => $homeStats,
            'away' => $awayStats,
        ], 200, [
            'Cache-Control' => "public, max-age={$maxAge}, stale-while-revalidate=120",
        ]);
    }

    public function lineups(int $fixtureId): JsonResponse
    {
        $fixture = DB::table('fixtures')->where('id', $fixtureId)->first();
        if (! $fixture) {
            return response()->json(['message' => 'Fixture not found.'], 404);
        }

        $lineups = DB::table('fixture_lineups as fl')
            ->join('teams as t', 't.id', '=', 'fl.team_id')
            ->where('fl.fixture_id', $fixtureId)
            ->select([
                'fl.id',
                'fl.team_id',
                't.name as team_name',
                't.slug as team_slug',
                'fl.formation',
                'fl.coach_name',
            ])
            ->get();

        $lineupIds = $lineups->pluck('id')->toArray();
        $players = DB::table('fixture_lineup_players')
            ->whereIn('fixture_lineup_id', $lineupIds)
            ->orderBy('is_starter', 'desc')
            ->orderBy('grid_row', 'asc')
            ->orderBy('shirt_number', 'asc')
            ->get();

        $formatted = [];
        foreach ($lineups as $lineup) {
            $teamPlayers = $players->where('fixture_lineup_id', $lineup->id);
            $side = ($lineup->team_id === $fixture->home_team_id) ? 'home' : 'away';

            $formatted[$side] = [
                'team_id' => $lineup->team_id,
                'team_name' => $lineup->team_name,
                'team_slug' => $lineup->team_slug,
                'formation' => $lineup->formation,
                'coach_name' => $lineup->coach_name,
                'starters' => $teamPlayers->where('is_starter', true)->values(),
                'bench' => $teamPlayers->where('is_starter', false)->values(),
            ];
        }

        return response()->json([
            'fixture_id' => $fixtureId,
            'home' => $formatted['home'] ?? null,
            'away' => $formatted['away'] ?? null,
        ], 200, [
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=120',
        ]);
    }
}
