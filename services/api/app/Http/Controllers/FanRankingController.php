<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class FanRankingController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $this->authorizedReader($request, 'reader:read');

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $limit = (int) ($validated['limit'] ?? 25);

        $rows = DB::table('prediction_settlements as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->groupBy('s.user_id', 'u.name')
            ->selectRaw('
                s.user_id,
                u.name as display_name,
                SUM(s.total_points) as total_points,
                SUM(CASE WHEN s.is_exact_score_hit THEN 1 ELSE 0 END) as exact_score_hits,
                SUM(CASE WHEN s.is_outcome_hit THEN 1 ELSE 0 END) as outcome_hits,
                COUNT(s.id) as settled_predictions_count
            ')
            ->orderByRaw('SUM(s.total_points) DESC')
            ->orderByRaw('SUM(CASE WHEN s.is_exact_score_hit THEN 1 ELSE 0 END) DESC')
            ->orderByRaw('COUNT(s.id) ASC')
            ->orderBy('s.user_id')
            ->limit($limit)
            ->get();

        $leaderboard = [];
        foreach ($rows as $index => $row) {
            $settledCount = (int) $row->settled_predictions_count;
            $outcomeHits = (int) $row->outcome_hits;
            $exactHits = (int) $row->exact_score_hits;
            $accuracyPct = $settledCount > 0
                ? round(($outcomeHits / $settledCount) * 100, 1)
                : 0.0;

            $badges = [];
            if ($exactHits >= 1) {
                $badges[] = 'score_sniper';
            }
            if ($settledCount >= 3 && $accuracyPct >= 60.0) {
                $badges[] = 'sharp_analyst';
            }
            if ($settledCount >= 5) {
                $badges[] = 'matchday_regular';
            }

            $leaderboard[] = [
                'rank' => $index + 1,
                'user_id' => (int) $row->user_id,
                'display_name' => $row->display_name,
                'total_points' => (int) $row->total_points,
                'exact_score_hits' => $exactHits,
                'outcome_hits' => $outcomeHits,
                'settled_predictions_count' => $settledCount,
                'outcome_accuracy_pct' => $accuracyPct,
                'badges' => $badges,
            ];
        }

        return response()->json([
            'rankings' => $leaderboard,
        ]);
    }

    public function clubs(Request $request): JsonResponse
    {
        $this->authorizedReader($request, 'reader:read');

        $minSampleSize = (int) (DB::table('prediction_policies')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->value('min_club_ranking_sample_size') ?? 5);

        $rows = DB::table('teams as t')
            ->join('reader_favorite_teams as rft', 'rft.team_id', '=', 't.id')
            ->join('prediction_settlements as s', 's.user_id', '=', 'rft.user_id')
            ->groupBy('t.id', 't.slug', 't.name')
            ->selectRaw('
                t.id as team_id,
                t.slug as team_slug,
                t.name as team_name,
                COUNT(DISTINCT s.user_id) as active_supporters_count,
                COUNT(s.id) as settled_predictions_count,
                SUM(s.total_points) as total_points,
                ROUND(AVG(s.total_points)::numeric, 2) as avg_points_per_prediction,
                SUM(CASE WHEN s.is_exact_score_hit THEN 1 ELSE 0 END) as exact_score_hits
            ')
            ->orderByRaw('CASE WHEN COUNT(DISTINCT s.user_id) >= ? THEN 0 ELSE 1 END ASC', [$minSampleSize])
            ->orderByRaw('ROUND(AVG(s.total_points)::numeric, 2) DESC')
            ->orderByRaw('SUM(s.total_points) DESC')
            ->orderBy('t.id')
            ->get();

        $clubs = [];
        foreach ($rows as $index => $row) {
            $supportersCount = (int) $row->active_supporters_count;
            $clubs[] = [
                'rank' => $index + 1,
                'team_id' => (int) $row->team_id,
                'team_slug' => $row->team_slug,
                'team_name' => $row->team_name,
                'active_supporters_count' => $supportersCount,
                'min_required_supporters' => $minSampleSize,
                'is_qualified_for_official_rank' => $supportersCount >= $minSampleSize,
                'settled_predictions_count' => (int) $row->settled_predictions_count,
                'total_points' => (int) $row->total_points,
                'avg_points_per_prediction' => (float) $row->avg_points_per_prediction,
                'exact_score_hits' => (int) $row->exact_score_hits,
            ];
        }

        return response()->json([
            'min_club_ranking_sample_size' => $minSampleSize,
            'club_rankings' => $clubs,
        ]);
    }

    private function authorizedReader(Request $request, string $ability): User
    {
        $reader = $request->user();
        $raw = $request->bearerToken();
        $token = $raw ? PersonalAccessToken::findToken($raw) : null;
        if (! $reader instanceof User || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || $token->tokenable_type !== $reader->getMorphClass()
            || (string) $token->tokenable_id !== (string) $reader->getKey()
            || ! $token->can($ability)) {
            abort(403);
        }

        return $reader;
    }
}
