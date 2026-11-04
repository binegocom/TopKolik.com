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
}
