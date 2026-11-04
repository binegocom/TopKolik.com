<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\CompetitionStanding;
use App\Models\Fixture;
use App\Models\Team;
use App\Services\TopkolikCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TeamDetailController extends Controller
{
    public function __construct(
        private readonly TopkolikCacheService $cacheService,
    ) {}

    public function show(Request $request, string $identifier): JsonResponse|Response
    {
        $team = is_numeric($identifier)
            ? Team::with(['sport', 'venue'])->find((int) $identifier)
            : Team::with(['sport', 'venue'])->where('slug', $identifier)->first();

        if ($team === null) {
            return response()->json([
                'message' => 'Team not found',
            ], 404);
        }

        // 1. Squad / Athletes
        $athletes = Athlete::query()
            ->where('current_team_id', $team->id)
            ->where('is_active', true)
            ->orderByRaw("CASE position WHEN 'GK' THEN 1 WHEN 'DF' THEN 2 WHEN 'MF' THEN 3 WHEN 'FW' THEN 4 ELSE 5 END")
            ->orderBy('shirt_number')
            ->get([
                'id',
                'slug',
                'name',
                'first_name',
                'last_name',
                'nationality',
                'position',
                'shirt_number',
                'photo_url',
            ]);

        // 2. Recent finished fixtures with result calculation (W/D/L)
        $recentFixtures = Fixture::query()
            ->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->where('status', 'finished')
            ->with(['competition', 'homeTeam', 'awayTeam'])
            ->orderByDesc('starts_at')
            ->limit(5)
            ->get()
            ->map(function (Fixture $f) use ($team) {
                $isHome = $f->home_team_id === $team->id;
                $teamScore = $isHome ? $f->home_score : $f->away_score;
                $oppScore = $isHome ? $f->away_score : $f->home_score;
                $result = ($teamScore > $oppScore) ? 'W' : (($teamScore < $oppScore) ? 'L' : 'D');

                return [
                    'id' => $f->id,
                    'competition' => [
                        'id' => $f->competition?->id,
                        'name' => $f->competition?->name,
                        'slug' => $f->competition?->slug,
                    ],
                    'starts_at' => $f->starts_at?->toIso8601String(),
                    'home_team' => ['id' => $f->homeTeam?->id, 'name' => $f->homeTeam?->name, 'slug' => $f->homeTeam?->slug],
                    'away_team' => ['id' => $f->awayTeam?->id, 'name' => $f->awayTeam?->name, 'slug' => $f->awayTeam?->slug],
                    'home_score' => $f->home_score,
                    'away_score' => $f->away_score,
                    'result' => $result,
                ];
            });

        // 3. Upcoming fixtures
        $upcomingFixtures = Fixture::query()
            ->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->whereIn('status', ['scheduled', 'live'])
            ->with(['competition', 'homeTeam', 'awayTeam'])
            ->orderBy('starts_at')
            ->limit(5)
            ->get()
            ->map(fn (Fixture $f) => [
                'id' => $f->id,
                'status' => $f->status,
                'competition' => [
                    'id' => $f->competition?->id,
                    'name' => $f->competition?->name,
                    'slug' => $f->competition?->slug,
                ],
                'starts_at' => $f->starts_at?->toIso8601String(),
                'home_team' => ['id' => $f->homeTeam?->id, 'name' => $f->homeTeam?->name, 'slug' => $f->homeTeam?->slug],
                'away_team' => ['id' => $f->awayTeam?->id, 'name' => $f->awayTeam?->name, 'slug' => $f->awayTeam?->slug],
            ]);

        // 4. Current standing
        $standing = CompetitionStanding::query()
            ->where('team_id', $team->id)
            ->with(['competition', 'season'])
            ->first();

        $payload = [
            'team' => [
                'id' => $team->id,
                'slug' => $team->slug,
                'name' => $team->name,
                'short_name' => $team->short_name,
                'logo_url' => $team->logo_url,
                'primary_color' => $team->primary_color,
                'secondary_color' => $team->secondary_color,
                'sport' => [
                    'id' => $team->sport?->id,
                    'name' => $team->sport?->name,
                    'slug' => $team->sport?->slug,
                ],
                'venue' => $team->venue ? [
                    'id' => $team->venue->id,
                    'name' => $team->venue->name,
                    'city' => $team->venue->city,
                    'capacity' => $team->venue->capacity,
                    'surface' => $team->venue->surface,
                ] : null,
            ],
            'squad' => $athletes,
            'recent_fixtures' => $recentFixtures,
            'upcoming_fixtures' => $upcomingFixtures,
            'standing' => $standing ? [
                'competition' => $standing->competition?->name,
                'season' => $standing->season?->label,
                'position' => $standing->position,
                'played' => $standing->played,
                'won' => $standing->won,
                'drawn' => $standing->drawn,
                'lost' => $standing->lost,
                'goals_for' => $standing->goals_for,
                'goals_against' => $standing->goals_against,
                'goal_difference' => $standing->goal_difference,
                'points' => $standing->points,
                'form' => $standing->form,
            ] : null,
        ];

        return $this->cacheService->makeCachedResponse($request, $payload, 180, true);
    }
}
