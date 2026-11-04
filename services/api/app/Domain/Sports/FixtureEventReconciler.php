<?php

declare(strict_types=1);

namespace App\Domain\Sports;

use App\Models\Fixture;
use Illuminate\Support\Facades\DB;

final class FixtureEventReconciler
{
    /**
     * Ingest or reconcile match events with out-of-order handling and VAR score recomputation.
     */
    public function recordEvent(
        int $fixtureId,
        int $teamId,
        string $eventType,
        int $minute,
        ?int $extraMinute = null,
        ?string $playerName = null,
        ?string $detail = null,
        string $period = '1h',
        string $sourceProvenance = 'topkolik:feed',
    ): int {
        $id = (int) DB::table('fixture_events')->insertGetId([
            'fixture_id' => $fixtureId,
            'team_id' => $teamId,
            'event_type' => $eventType,
            'minute' => $minute,
            'extra_minute' => $extraMinute,
            'period' => $minute > 45 ? '2h' : $period,
            'player_name' => $playerName,
            'detail' => $detail,
            'is_var_cancelled' => false,
            'source_provenance' => $sourceProvenance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->reconcileFixtureScore($fixtureId);

        return $id;
    }

    /**
     * Cancel an event (e.g. VAR disallows goal) and recompute the score.
     */
    public function cancelEvent(int $eventId, string $varReason): void
    {
        $event = DB::table('fixture_events')->where('id', $eventId)->first();
        if (! $event) {
            return;
        }

        DB::table('fixture_events')->where('id', $eventId)->update([
            'is_var_cancelled' => true,
            'detail' => $event->detail ? "{$event->detail} [VAR: {$varReason}]" : "[VAR: {$varReason}]",
            'updated_at' => now(),
        ]);

        $this->reconcileFixtureScore((int) $event->fixture_id);
    }

    /**
     * Recompute match score and first scoring team based on all active valid goals.
     */
    public function reconcileFixtureScore(int $fixtureId): void
    {
        $fixture = Fixture::find($fixtureId);
        if (! $fixture) {
            return;
        }

        // Active non-cancelled goals
        $goals = DB::table('fixture_events')
            ->where('fixture_id', $fixtureId)
            ->where('event_type', 'goal')
            ->where('is_var_cancelled', false)
            ->orderBy('minute')
            ->orderBy('extra_minute')
            ->orderBy('id')
            ->get();

        $homeGoals = 0;
        $awayGoals = 0;
        $firstScorer = null;

        foreach ($goals as $goal) {
            if ($goal->team_id === $fixture->home_team_id) {
                $homeGoals++;
                if ($firstScorer === null) {
                    $firstScorer = 'home';
                }
            } elseif ($goal->team_id === $fixture->away_team_id) {
                $awayGoals++;
                if ($firstScorer === null) {
                    $firstScorer = 'away';
                }
            }
        }

        $fixture->home_score = $homeGoals;
        $fixture->away_score = $awayGoals;
        $fixture->first_scoring_team = $firstScorer;
        $fixture->source_revision += 1;
        $fixture->save();
    }
}
