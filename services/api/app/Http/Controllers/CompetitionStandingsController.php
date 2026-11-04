<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CompetitionStandingsController extends Controller
{
    public function standings(int $competitionId, Request $request): JsonResponse
    {
        $competition = DB::table('competitions')->where('id', $competitionId)->first();
        if (! $competition) {
            return response()->json(['message' => 'Competition not found.'], 404);
        }

        $season = DB::table('seasons')
            ->where('competition_id', $competitionId)
            ->where('is_current', true)
            ->first();

        if (! $season) {
            $season = DB::table('seasons')
                ->where('competition_id', $competitionId)
                ->orderBy('id', 'desc')
                ->first();
        }

        if (! $season) {
            return response()->json([
                'competition' => $competition,
                'season' => null,
                'standings' => [],
            ], 200, [
                'Cache-Control' => 'public, max-age=120, stale-while-revalidate=300',
            ]);
        }

        $standings = DB::table('competition_standings as cs')
            ->join('teams as t', 't.id', '=', 'cs.team_id')
            ->where('cs.competition_id', $competitionId)
            ->where('cs.season_id', $season->id)
            ->select([
                'cs.position',
                'cs.team_id',
                't.name as team_name',
                't.slug as team_slug',
                'cs.played',
                'cs.won',
                'cs.drawn',
                'cs.lost',
                'cs.goals_for',
                'cs.goals_against',
                'cs.goal_difference',
                'cs.points',
                'cs.form',
                'cs.source_provenance',
            ])
            ->orderBy('cs.position', 'asc')
            ->orderBy('cs.points', 'desc')
            ->orderBy('cs.goal_difference', 'desc')
            ->orderBy('cs.goals_for', 'desc')
            ->get();

        return response()->json([
            'competition' => [
                'id' => $competition->id,
                'name' => $competition->name,
                'slug' => $competition->slug,
                'country_code' => $competition->country_code,
            ],
            'season' => [
                'id' => $season->id,
                'label' => $season->label,
                'is_current' => (bool) $season->is_current,
            ],
            'standings' => $standings,
        ], 200, [
            'Cache-Control' => 'public, max-age=120, stale-while-revalidate=300',
        ]);
    }
}
