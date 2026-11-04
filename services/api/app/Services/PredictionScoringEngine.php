<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PredictionScoringEngine
{
    public const DEFAULT_POLICY_VERSION = 'v1.0-topkolik-standard';

    public static function deriveOutcome(int $homeScore, int $awayScore): string
    {
        if ($homeScore > $awayScore) {
            return 'home';
        }
        if ($awayScore > $homeScore) {
            return 'away';
        }

        return 'draw';
    }

    public static function deriveGoalRange(int $homeScore, int $awayScore): string
    {
        $total = $homeScore + $awayScore;
        if ($total <= 1) {
            return '0_1';
        }
        if ($total <= 3) {
            return '2_3';
        }

        return '4_plus';
    }

    /**
     * Pure deterministic point calculation for a single prediction against official match facts.
     *
     * @param  array{outcome_points: int, exact_score_points: int, goal_range_points: int, first_scorer_team_points: int}  $policy
     * @return array{
     *     outcome_points_awarded: int,
     *     exact_score_points_awarded: int,
     *     goal_range_points_awarded: int,
     *     first_scorer_points_awarded: int,
     *     total_points: int,
     *     is_exact_score_hit: bool,
     *     is_outcome_hit: bool
     * }
     */
    public function scorePrediction(
        string $predictedOutcome,
        int $predictedHomeScore,
        int $predictedAwayScore,
        string $predictedGoalRange,
        string $predictedFirstScoringTeam,
        int $actualHomeScore,
        int $actualAwayScore,
        string $actualFirstScoringTeam,
        array $policy,
    ): array {
        $actualOutcome = self::deriveOutcome($actualHomeScore, $actualAwayScore);
        $actualGoalRange = self::deriveGoalRange($actualHomeScore, $actualAwayScore);

        $isOutcomeHit = $predictedOutcome === $actualOutcome;
        $isExactScoreHit = $predictedHomeScore === $actualHomeScore
            && $predictedAwayScore === $actualAwayScore;
        $isGoalRangeHit = $predictedGoalRange === $actualGoalRange;
        $isFirstScorerHit = $predictedFirstScoringTeam === $actualFirstScoringTeam;

        $outcomePts = $isOutcomeHit ? (int) $policy['outcome_points'] : 0;
        $exactScorePts = $isExactScoreHit ? (int) $policy['exact_score_points'] : 0;
        $goalRangePts = $isGoalRangeHit ? (int) $policy['goal_range_points'] : 0;
        $firstScorerPts = $isFirstScorerHit ? (int) $policy['first_scorer_team_points'] : 0;

        return [
            'outcome_points_awarded' => $outcomePts,
            'exact_score_points_awarded' => $exactScorePts,
            'goal_range_points_awarded' => $goalRangePts,
            'first_scorer_points_awarded' => $firstScorerPts,
            'total_points' => $outcomePts + $exactScorePts + $goalRangePts + $firstScorerPts,
            'is_exact_score_hit' => $isExactScoreHit,
            'is_outcome_hit' => $isOutcomeHit,
        ];
    }

    /**
     * Settle or recompute all predictions for a finished fixture inside a PostgreSQL transaction.
     * Supports official score/VAR corrections via source_revision tracking.
     */
    public function settleFixture(int $fixtureId): int
    {
        return DB::transaction(function () use ($fixtureId): int {
            $fixture = DB::table('fixtures')
                ->where('id', $fixtureId)
                ->lockForUpdate()
                ->first();

            if (! $fixture || $fixture->status !== 'finished'
                || $fixture->home_score === null || $fixture->away_score === null) {
                throw new InvalidArgumentException('Fixture is not in a settled finished state.');
            }

            $actualHome = (int) $fixture->home_score;
            $actualAway = (int) $fixture->away_score;
            $actualFirstScorer = $fixture->first_scoring_team
                ?? ($actualHome === 0 && $actualAway === 0 ? 'none' : 'home');
            $revision = (int) $fixture->source_revision;

            $policies = DB::table('prediction_policies')
                ->get()
                ->keyBy('version_code');

            $predictions = DB::table('match_predictions')
                ->where('fixture_id', $fixtureId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $settledCount = 0;
            $now = now();

            foreach ($predictions as $prediction) {
                $policyRow = $policies->get($prediction->policy_version);
                $policy = [
                    'outcome_points' => $policyRow ? (int) $policyRow->outcome_points : 3,
                    'exact_score_points' => $policyRow ? (int) $policyRow->exact_score_points : 5,
                    'goal_range_points' => $policyRow ? (int) $policyRow->goal_range_points : 2,
                    'first_scorer_team_points' => $policyRow ? (int) $policyRow->first_scorer_team_points : 2,
                ];

                $scored = $this->scorePrediction(
                    (string) $prediction->predicted_outcome,
                    (int) $prediction->predicted_home_score,
                    (int) $prediction->predicted_away_score,
                    (string) $prediction->predicted_goal_range,
                    (string) $prediction->predicted_first_scoring_team,
                    $actualHome,
                    $actualAway,
                    (string) $actualFirstScorer,
                    $policy,
                );

                DB::table('prediction_settlements')->updateOrInsert(
                    ['match_prediction_id' => $prediction->id],
                    [
                        'fixture_id' => $fixtureId,
                        'user_id' => $prediction->user_id,
                        'policy_version' => $prediction->policy_version,
                        'outcome_points_awarded' => $scored['outcome_points_awarded'],
                        'exact_score_points_awarded' => $scored['exact_score_points_awarded'],
                        'goal_range_points_awarded' => $scored['goal_range_points_awarded'],
                        'first_scorer_points_awarded' => $scored['first_scorer_points_awarded'],
                        'total_points' => $scored['total_points'],
                        'is_exact_score_hit' => $scored['is_exact_score_hit'],
                        'is_outcome_hit' => $scored['is_outcome_hit'],
                        'fixture_source_revision' => $revision,
                        'settled_at' => $now,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
                $settledCount++;
            }

            return $settledCount;
        });
    }
}
