<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Services\TopkolikCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AthleteDetailController extends Controller
{
    public function __construct(
        private readonly TopkolikCacheService $cacheService,
    ) {}

    public function show(Request $request, string $identifier): JsonResponse|Response
    {
        $athlete = is_numeric($identifier)
            ? Athlete::with(['sport', 'currentTeam', 'seasonStatistics.team'])->find((int) $identifier)
            : Athlete::with(['sport', 'currentTeam', 'seasonStatistics.team'])->where('slug', $identifier)->first();

        if ($athlete === null) {
            return response()->json([
                'message' => 'Athlete not found',
            ], 404);
        }

        $stats = $athlete->seasonStatistics->map(fn ($s) => [
            'id' => $s->id,
            'team' => [
                'id' => $s->team?->id,
                'name' => $s->team?->name,
                'slug' => $s->team?->slug,
            ],
            'appearances' => $s->appearances,
            'minutes_played' => $s->minutes_played,
            'goals' => $s->goals,
            'assists' => $s->assists,
            'yellow_cards' => $s->yellow_cards,
            'red_cards' => $s->red_cards,
            'rating' => $s->rating,
        ]);

        $payload = [
            'athlete' => [
                'id' => $athlete->id,
                'slug' => $athlete->slug,
                'name' => $athlete->name,
                'first_name' => $athlete->first_name,
                'last_name' => $athlete->last_name,
                'nationality' => $athlete->nationality,
                'country_code' => $athlete->country_code,
                'position' => $athlete->position,
                'shirt_number' => $athlete->shirt_number,
                'birth_date' => $athlete->birth_date?->format('Y-m-d'),
                'height_cm' => $athlete->height_cm,
                'weight_kg' => $athlete->weight_kg,
                'preferred_foot' => $athlete->preferred_foot,
                'photo_url' => $athlete->photo_url,
                'sport' => [
                    'id' => $athlete->sport?->id,
                    'name' => $athlete->sport?->name,
                    'slug' => $athlete->sport?->slug,
                ],
                'current_team' => $athlete->currentTeam ? [
                    'id' => $athlete->currentTeam->id,
                    'name' => $athlete->currentTeam->name,
                    'slug' => $athlete->currentTeam->slug,
                    'logo_url' => $athlete->currentTeam->logo_url,
                ] : null,
            ],
            'season_statistics' => $stats,
        ];

        return $this->cacheService->makeCachedResponse($request, $payload, 180, true);
    }
}

