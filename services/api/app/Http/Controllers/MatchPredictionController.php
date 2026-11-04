<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PredictionScoringEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

final class MatchPredictionController extends Controller
{
    public function showOwn(Request $request, int $fixtureId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:read');

        $fixture = DB::table('fixtures')->where('id', $fixtureId)->first();
        if (! $fixture) {
            return response()->json(['message' => 'Fixture not found.'], 404);
        }

        $prediction = DB::table('match_predictions')
            ->where('fixture_id', $fixtureId)
            ->where('user_id', $reader->getKey())
            ->first();

        $settlement = $prediction
            ? DB::table('prediction_settlements')
                ->where('match_prediction_id', $prediction->id)
                ->first()
            : null;

        $isLocked = $fixture->status !== 'scheduled'
            || Carbon::parse($fixture->starts_at)->lte(now());

        return response()->json([
            'fixture_id' => $fixtureId,
            'is_locked' => $isLocked,
            'locks_at' => Carbon::parse($fixture->starts_at)->toIso8601String(),
            'prediction' => $prediction ? [
                'id' => (int) $prediction->id,
                'policy_version' => $prediction->policy_version,
                'predicted_outcome' => $prediction->predicted_outcome,
                'predicted_home_score' => (int) $prediction->predicted_home_score,
                'predicted_away_score' => (int) $prediction->predicted_away_score,
                'predicted_goal_range' => $prediction->predicted_goal_range,
                'predicted_first_scoring_team' => $prediction->predicted_first_scoring_team,
                'submitted_at' => Carbon::parse($prediction->submitted_at)->toIso8601String(),
                'settlement' => $settlement ? [
                    'outcome_points_awarded' => (int) $settlement->outcome_points_awarded,
                    'exact_score_points_awarded' => (int) $settlement->exact_score_points_awarded,
                    'goal_range_points_awarded' => (int) $settlement->goal_range_points_awarded,
                    'first_scorer_points_awarded' => (int) $settlement->first_scorer_points_awarded,
                    'total_points' => (int) $settlement->total_points,
                    'is_exact_score_hit' => (bool) $settlement->is_exact_score_hit,
                    'is_outcome_hit' => (bool) $settlement->is_outcome_hit,
                    'fixture_source_revision' => (int) $settlement->fixture_source_revision,
                    'settled_at' => Carbon::parse($settlement->settled_at)->toIso8601String(),
                ] : null,
            ] : null,
        ]);
    }

    public function submit(Request $request, int $fixtureId): JsonResponse
    {
        $reader = $this->authorizedReader($request, 'reader:write');

        $validated = $request->validate([
            'predicted_outcome' => ['required', 'string', Rule::in(['home', 'draw', 'away'])],
            'predicted_home_score' => ['required', 'integer', 'between:0,25'],
            'predicted_away_score' => ['required', 'integer', 'between:0,25'],
            'predicted_goal_range' => ['required', 'string', Rule::in(['0_1', '2_3', '4_plus'])],
            'predicted_first_scoring_team' => ['required', 'string', Rule::in(['home', 'away', 'none'])],
            // Predictions are strictly free-to-play skill challenges; reject any wagering or identity fields.
            'user_id' => ['missing'],
            'stake' => ['missing'],
            'wager' => ['missing'],
            'amount' => ['missing'],
            'odds' => ['missing'],
            'currency' => ['missing'],
        ]);

        $homeScore = (int) $validated['predicted_home_score'];
        $awayScore = (int) $validated['predicted_away_score'];
        $outcome = (string) $validated['predicted_outcome'];
        $goalRange = (string) $validated['predicted_goal_range'];
        $firstScorer = (string) $validated['predicted_first_scoring_team'];

        $expectedOutcome = PredictionScoringEngine::deriveOutcome($homeScore, $awayScore);
        if ($outcome !== $expectedOutcome) {
            return response()->json([
                'message' => 'Predicted outcome does not match the predicted scoreline.',
                'errors' => ['predicted_outcome' => ['Predicted outcome must match the predicted scoreline.']],
            ], 422);
        }

        $expectedRange = PredictionScoringEngine::deriveGoalRange($homeScore, $awayScore);
        if ($goalRange !== $expectedRange) {
            return response()->json([
                'message' => 'Predicted goal range does not match the predicted scoreline.',
                'errors' => ['predicted_goal_range' => ['Predicted goal range must match total predicted goals.']],
            ], 422);
        }

        if ($homeScore === 0 && $awayScore === 0 && $firstScorer !== 'none') {
            return response()->json([
                'message' => 'A 0-0 prediction requires first scoring team to be none.',
                'errors' => ['predicted_first_scoring_team' => ['Must be none for a 0-0 scoreline.']],
            ], 422);
        }
        if (($homeScore > 0 || $awayScore > 0) && $firstScorer === 'none') {
            return response()->json([
                'message' => 'A non-zero scoreline requires a first scoring team.',
                'errors' => ['predicted_first_scoring_team' => ['Select home or away when goals are predicted.']],
            ], 422);
        }
        if ($homeScore === 0 && $awayScore > 0 && $firstScorer === 'home') {
            return response()->json([
                'message' => 'Home team cannot score first when predicted home goals are zero.',
                'errors' => ['predicted_first_scoring_team' => ['Home team has 0 predicted goals.']],
            ], 422);
        }
        if ($awayScore === 0 && $homeScore > 0 && $firstScorer === 'away') {
            return response()->json([
                'message' => 'Away team cannot score first when predicted away goals are zero.',
                'errors' => ['predicted_first_scoring_team' => ['Away team has 0 predicted goals.']],
            ], 422);
        }

        return DB::transaction(function () use ($reader, $fixtureId, $homeScore, $awayScore, $outcome, $goalRange, $firstScorer): JsonResponse {
            $lockedReader = User::query()->whereKey($reader->getKey())->lockForUpdate()->firstOrFail();

            $fixture = DB::table('fixtures')
                ->where('id', $fixtureId)
                ->lockForUpdate()
                ->first();

            if (! $fixture) {
                return response()->json(['message' => 'Fixture not found.'], 404);
            }

            if ($fixture->status !== 'scheduled' || Carbon::parse($fixture->starts_at)->lte(now())) {
                return response()->json([
                    'message' => 'Prediction window is locked after scheduled kick-off.',
                ], 409);
            }

            $activePolicy = DB::table('prediction_policies')
                ->where('is_active', true)
                ->orderByDesc('id')
                ->value('version_code') ?? PredictionScoringEngine::DEFAULT_POLICY_VERSION;

            $now = now();
            DB::table('match_predictions')->updateOrInsert(
                [
                    'fixture_id' => $fixtureId,
                    'user_id' => $lockedReader->getKey(),
                ],
                [
                    'policy_version' => $activePolicy,
                    'predicted_outcome' => $outcome,
                    'predicted_home_score' => $homeScore,
                    'predicted_away_score' => $awayScore,
                    'predicted_goal_range' => $goalRange,
                    'predicted_first_scoring_team' => $firstScorer,
                    'submitted_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            $saved = DB::table('match_predictions')
                ->where('fixture_id', $fixtureId)
                ->where('user_id', $lockedReader->getKey())
                ->first();

            return response()->json([
                'prediction' => [
                    'id' => (int) $saved->id,
                    'fixture_id' => $fixtureId,
                    'policy_version' => $saved->policy_version,
                    'predicted_outcome' => $saved->predicted_outcome,
                    'predicted_home_score' => (int) $saved->predicted_home_score,
                    'predicted_away_score' => (int) $saved->predicted_away_score,
                    'predicted_goal_range' => $saved->predicted_goal_range,
                    'predicted_first_scoring_team' => $saved->predicted_first_scoring_team,
                    'submitted_at' => Carbon::parse($saved->submitted_at)->toIso8601String(),
                ],
            ]);
        });
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
