<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\CompetitionStanding;
use App\Models\Fixture;
use App\Models\Season;
use App\Services\TopkolikCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CompetitionDetailController extends Controller
{
    public function __construct(
        private readonly TopkolikCacheService $cacheService,
    ) {}

    public function show(Request $request, string $identifier): JsonResponse|Response
    {
        $competition = is_numeric($identifier)
            ? Competition::with('sport')->find((int) $identifier)
            : Competition::with('sport')->where('slug', $identifier)->first();

        if ($competition === null) {
            return response()->json([
                'message' => 'Competition not found',
            ], 404);
        }

        // 1. Current Season
        $season = Season::query()
            ->where('competition_id', $competition->id)
            ->where('is_current', true)
            ->first() ?? Season::query()
            ->where('competition_id', $competition->id)
            ->orderByDesc('id')
            ->first();

        // 2. Standings
        $standings = CompetitionStanding::query()
            ->where('competition_id', $competition->id)
            ->when($season, fn ($q) => $q->where('season_id', $season->id))
            ->with('team')
            ->orderBy('position')
            ->get()
            ->map(fn (CompetitionStanding $s) => [
                'position' => $s->position,
                'team' => [
                    'id' => $s->team?->id,
                    'name' => $s->team?->name,
                    'slug' => $s->team?->slug,
                    'logo_url' => $s->team?->logo_url,
                ],
                'played' => $s->played,
                'won' => $s->won,
                'drawn' => $s->drawn,
                'lost' => $s->lost,
                'goals_for' => $s->goals_for,
                'goals_against' => $s->goals_against,
                'goal_difference' => $s->goal_difference,
                'points' => $s->points,
                'form' => $s->form,
            ]);

        // 3. Recent Results
        $recentFixtures = Fixture::query()
            ->where('competition_id', $competition->id)
            ->where('status', 'finished')
            ->with(['homeTeam', 'awayTeam'])
            ->orderByDesc('starts_at')
            ->limit(10)
            ->get()
            ->map(fn (Fixture $f) => [
                'id' => $f->id,
                'starts_at' => $f->starts_at?->toIso8601String(),
                'home_team' => ['id' => $f->homeTeam?->id, 'name' => $f->homeTeam?->name, 'slug' => $f->homeTeam?->slug],
                'away_team' => ['id' => $f->awayTeam?->id, 'name' => $f->awayTeam?->name, 'slug' => $f->awayTeam?->slug],
                'home_score' => $f->home_score,
                'away_score' => $f->away_score,
            ]);

        // 4. Upcoming Fixtures
        $upcomingFixtures = Fixture::query()
            ->where('competition_id', $competition->id)
            ->whereIn('status', ['scheduled', 'live'])
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(fn (Fixture $f) => [
                'id' => $f->id,
                'status' => $f->status,
                'starts_at' => $f->starts_at?->toIso8601String(),
                'home_team' => ['id' => $f->homeTeam?->id, 'name' => $f->homeTeam?->name, 'slug' => $f->homeTeam?->slug],
                'away_team' => ['id' => $f->awayTeam?->id, 'name' => $f->awayTeam?->name, 'slug' => $f->awayTeam?->slug],
            ]);

        $payload = [
            'competition' => [
                'id' => $competition->id,
                'slug' => $competition->slug,
                'name' => $competition->name,
                'country_code' => $competition->country_code,
                'sport' => [
                    'id' => $competition->sport?->id,
                    'name' => $competition->sport?->name,
                    'slug' => $competition->sport?->slug,
                ],
            ],
            'season' => $season ? [
                'id' => $season->id,
                'label' => $season->label,
                'starts_on' => $season->starts_on?->format('Y-m-d'),
                'ends_on' => $season->ends_on?->format('Y-m-d'),
            ] : null,
            'standings' => $standings,
            'recent_fixtures' => $recentFixtures,
            'upcoming_fixtures' => $upcomingFixtures,
        ];

        return $this->cacheService->makeCachedResponse($request, $payload, 180, true);
    }
}
