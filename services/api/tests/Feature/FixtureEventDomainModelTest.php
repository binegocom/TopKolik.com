<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sports\FixtureEventReconciler;
use App\Domain\Sports\FixtureStateMachine;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Sport;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class FixtureEventDomainModelTest extends TestCase
{
    use RefreshDatabase;

    private FixtureStateMachine $stateMachine;
    private FixtureEventReconciler $reconciler;
    private Fixture $fixture;
    private Team $homeTeam;
    private Team $awayTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new FixtureStateMachine();
        $this->reconciler = new FixtureEventReconciler();

        $football = Sport::create(['slug' => 'futbol', 'name' => 'Futbol']);

        $competition = Competition::create([
            'sport_id' => $football->id,
            'slug' => 'super-lig',
            'name' => 'Trendyol Süper Lig',
        ]);

        $season = Season::create([
            'competition_id' => $competition->id,
            'label' => '2026-2027',
            'is_current' => true,
        ]);

        $this->homeTeam = Team::create(['sport_id' => $football->id, 'slug' => 'gs', 'name' => 'Galatasaray']);
        $this->awayTeam = Team::create(['sport_id' => $football->id, 'slug' => 'fb', 'name' => 'Fenerbahçe']);

        $this->fixture = Fixture::create([
            'competition_id' => $competition->id,
            'season_id' => $season->id,
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $this->awayTeam->id,
            'status' => 'scheduled',
            'starts_at' => now()->subHours(2),
            'home_score' => null,
            'away_score' => null,
            'source_revision' => 1,
        ]);
    }

    public function test_state_machine_enforces_valid_lifecycle_transitions(): void
    {
        // 1. scheduled -> live
        $this->stateMachine->transition($this->fixture, 'live');
        $this->assertSame('live', $this->fixture->status);
        $this->assertSame(2, $this->fixture->source_revision);

        // 2. live -> finished
        $this->stateMachine->transition($this->fixture, 'finished');
        $this->assertSame('finished', $this->fixture->status);
        $this->assertSame(3, $this->fixture->source_revision);

        // 3. idempotent finished update
        $this->stateMachine->transition($this->fixture, 'finished');
        $this->assertSame('finished', $this->fixture->status);

        // 4. Illegal transition: finished -> scheduled must fail
        $this->expectException(InvalidArgumentException::class);
        $this->stateMachine->transition($this->fixture, 'scheduled');
    }

    public function test_out_of_order_event_ingestion_and_score_reconciliation(): void
    {
        // Out-of-order event 1: minute 72 goal arrives first
        $this->reconciler->recordEvent(
            fixtureId: $this->fixture->id,
            teamId: $this->homeTeam->id,
            eventType: 'goal',
            minute: 72,
            playerName: 'Victor Osimhen'
        );

        $this->fixture->refresh();
        $this->assertSame(1, $this->fixture->home_score);
        $this->assertSame(0, $this->fixture->away_score);
        $this->assertSame('home', $this->fixture->first_scoring_team);

        // Out-of-order event 2: minute 18 goal arrives later
        $this->reconciler->recordEvent(
            fixtureId: $this->fixture->id,
            teamId: $this->awayTeam->id,
            eventType: 'goal',
            minute: 18,
            playerName: 'Edin Džeko'
        );

        $this->fixture->refresh();
        $this->assertSame(1, $this->fixture->home_score);
        $this->assertSame(1, $this->fixture->away_score);
        // The first scoring team is now correctly away, because minute 18 < minute 72!
        $this->assertSame('away', $this->fixture->first_scoring_team);
    }

    public function test_var_cancellation_recalculates_score_and_first_scorer(): void
    {
        // Minute 10: away goal
        $goal1 = $this->reconciler->recordEvent(
            fixtureId: $this->fixture->id,
            teamId: $this->awayTeam->id,
            eventType: 'goal',
            minute: 10,
            playerName: 'Away Player'
        );

        // Minute 35: home goal
        $this->reconciler->recordEvent(
            fixtureId: $this->fixture->id,
            teamId: $this->homeTeam->id,
            eventType: 'goal',
            minute: 35,
            playerName: 'Home Player'
        );

        $this->fixture->refresh();
        $this->assertSame(1, $this->fixture->home_score);
        $this->assertSame(1, $this->fixture->away_score);
        $this->assertSame('away', $this->fixture->first_scoring_team);

        // VAR cancels the minute 10 away goal due to offside
        $this->reconciler->cancelEvent($goal1, 'Offside in buildup');

        $this->fixture->refresh();
        $this->assertSame(1, $this->fixture->home_score);
        $this->assertSame(0, $this->fixture->away_score);
        // First scoring team is now home (minute 35) because minute 10 was cancelled!
        $this->assertSame('home', $this->fixture->first_scoring_team);
    }
}

