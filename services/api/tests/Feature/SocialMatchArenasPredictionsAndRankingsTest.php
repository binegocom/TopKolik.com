<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\StaffMember;
use App\Models\Team;
use App\Models\User;
use App\Services\PredictionScoringEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SocialMatchArenasPredictionsAndRankingsTest extends TestCase
{
    use RefreshDatabase;

    private function reader(string $name = 'Fan Reader'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => 'fan-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
        ]);
    }

    private function readerToken(User $reader, array $abilities = ['reader:read', 'reader:write']): string
    {
        return $reader->createToken('mobile', $abilities, now()->addHours(2))->plainTextToken;
    }

    private function staff(string $role = 'support'): StaffMember
    {
        $staff = StaffMember::create([
            'name' => 'Arena Moderator',
            'email' => 'mod-'.Str::uuid().'@example.test',
            'password' => 'Strong!Moderator2026',
            'role' => $role,
        ]);
        $staff->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
        ])->save();

        return $staff;
    }

    private function staffToken(StaffMember $staff, bool $stepUp = true): string
    {
        return $staff->createToken(
            'staff-mod',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes(5),
        )->plainTextToken;
    }

    /**
     * @return array{sport: Sport, homeTeam: Team, awayTeam: Team, fixtureId: int, arenaId: int}
     */
    private function createFixtureAndArena(
        string $fixtureStatus = 'scheduled',
        int $startsInMinutes = 60,
        string $arenaState = 'pre_match_open',
        int $slowModeSeconds = 0,
    ): array {
        $sport = Sport::create(['slug' => 'football-'.Str::random(6), 'name' => 'Football']);
        $homeTeam = Team::create([
            'sport_id' => $sport->id,
            'slug' => 'home-club-'.Str::random(6),
            'name' => 'Home SK',
        ]);
        $awayTeam = Team::create([
            'sport_id' => $sport->id,
            'slug' => 'away-club-'.Str::random(6),
            'name' => 'Away FK',
        ]);

        $now = now();
        $competitionId = DB::table('competitions')->insertGetId([
            'sport_id' => $sport->id,
            'slug' => 'super-lig-'.Str::random(6),
            'name' => 'Süper Lig',
            'country_code' => 'TR',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $seasonId = DB::table('seasons')->insertGetId([
            'competition_id' => $competitionId,
            'label' => '2026/2027',
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fixtureId = DB::table('fixtures')->insertGetId([
            'competition_id' => $competitionId,
            'season_id' => $seasonId,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => $fixtureStatus,
            'starts_at' => $now->copy()->addMinutes($startsInMinutes),
            'source_revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $arenaId = DB::table('match_arenas')->insertGetId([
            'fixture_id' => $fixtureId,
            'state' => $arenaState,
            'posting_enabled' => true,
            'slow_mode_seconds' => $slowModeSeconds,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'sport' => $sport,
            'homeTeam' => $homeTeam,
            'awayTeam' => $awayTeam,
            'fixtureId' => (int) $fixtureId,
            'arenaId' => (int) $arenaId,
        ];
    }

    public function test_segmented_supporter_rooms_enforce_club_follow_and_safety_filter_quarantines_abuse(): void
    {
        $ctx = $this->createFixtureAndArena(slowModeSeconds: 15);
        $homeFan = $this->reader('Home Supporter');
        $neutralFan = $this->reader('Neutral Fan');

        DB::table('reader_favorite_teams')->insert([
            'user_id' => $homeFan->id,
            'team_id' => $ctx['homeTeam']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Neutral fan cannot post in the Home supporter section, but can post in Neutral.
        $this->withToken($this->readerToken($neutralFan))
            ->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/comments', [
                'section' => 'home',
                'body' => 'Trying to enter home tribune without following.',
            ])
            ->assertForbidden();

        $this->withToken($this->readerToken($neutralFan))
            ->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/comments', [
                'section' => 'neutral',
                'body' => 'Great tactical matchup in the midfield today!',
            ])
            ->assertCreated()
            ->assertJsonPath('comment.moderation_state', 'visible')
            ->assertJsonPath('comment.section', 'neutral');

        // Slow mode blocks rapid second comment from the same user in the same arena.
        $this->withToken($this->readerToken($neutralFan))
            ->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/comments', [
                'section' => 'neutral',
                'body' => 'Immediate second message during slow mode.',
            ])
            ->assertStatus(429);

        // Home fan can post in the Home supporter section.
        app('auth')->forgetGuards();
        $this->withToken($this->readerToken($homeFan))
            ->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/comments', [
                'section' => 'home',
                'body' => 'Our high press on the right wing will decide this match!',
            ])
            ->assertCreated()
            ->assertJsonPath('comment.section', 'home')
            ->assertJsonPath('comment.moderation_state', 'visible');

        // Unlicensed betting/stream spam is quarantined (202 Accepted) and never shown in visible feed.
        $spammer = $this->reader('Spam Account');
        app('auth')->forgetGuards();
        $this->withToken($this->readerToken($spammer))
            ->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/comments', [
                'section' => 'neutral',
                'body' => 'Canlı bahis sitesi deneme bonusu kaçak yayın linki!',
            ])
            ->assertStatus(202)
            ->assertJsonPath('comment.moderation_state', 'quarantined')
            ->assertJsonPath('comment.moderation_reason', 'unlicensed_betting_or_stream_spam');

        app('auth')->forgetGuards();
        $this->withToken($this->readerToken($homeFan))
            ->getJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena')
            ->assertOk()
            ->assertJsonCount(2, 'arena.comments');
    }

    public function test_blocking_reporting_polls_reactions_and_staff_mfa_moderation_work_end_to_end(): void
    {
        $ctx = $this->createFixtureAndArena();
        $alice = $this->reader('Alice');
        $bob = $this->reader('Bob');

        $pollId = DB::table('arena_polls')->insertGetId([
            'match_arena_id' => $ctx['arenaId'],
            'question_key' => 'winner_poll',
            'question_text' => 'Who wins tonight?',
            'is_closed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $optHome = DB::table('arena_poll_options')->insertGetId([
            'arena_poll_id' => $pollId,
            'option_key' => 'home',
            'label' => 'Home SK',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Alice votes in the poll (idempotent single vote; second vote returns 409) and reacts.
        $this->withToken($this->readerToken($alice))
            ->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/polls/'.$pollId.'/votes', [
                'option_id' => $optHome,
            ])
            ->assertCreated();

        $this->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/polls/'.$pollId.'/votes', [
            'option_id' => $optHome,
        ])->assertStatus(409);

        $this->putJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/reaction', [
            'reaction_type' => 'fire',
        ])->assertOk()->assertJsonPath('reaction.reaction_type', 'fire');

        // Bob posts a comment in neutral.
        app('auth')->forgetGuards();
        $bobCommentId = (int) $this->withToken($this->readerToken($bob))
            ->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/comments', [
                'section' => 'neutral',
                'body' => 'Provocative comment from Bob.',
            ])
            ->assertCreated()
            ->json('comment.id');

        // Bob cannot report his own comment or block himself.
        $this->postJson('/api/v1/arena/comments/'.$bobCommentId.'/reports', [
            'reason_category' => 'harassment',
        ])->assertUnprocessable();
        $this->putJson('/api/v1/me/blocks/'.$bob->id)->assertUnprocessable();

        // Alice reports Bob's comment and blocks Bob.
        app('auth')->forgetGuards();
        $reportId = (int) $this->withToken($this->readerToken($alice))
            ->postJson('/api/v1/arena/comments/'.$bobCommentId.'/reports', [
                'reason_category' => 'harassment',
                'details' => 'Target harassment in neutral room.',
            ])
            ->assertCreated()
            ->json('report.id');

        // Duplicate report by Alice is rejected with 409.
        $this->postJson('/api/v1/arena/comments/'.$bobCommentId.'/reports', [
            'reason_category' => 'harassment',
        ])->assertStatus(409);

        $this->putJson('/api/v1/me/blocks/'.$bob->id)
            ->assertOk()
            ->assertJsonPath('block.active', true);

        // Bob's comment is now filtered out of Alice's arena view, and Bob cannot reply to Alice.
        $this->getJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena')
            ->assertOk()
            ->assertJsonCount(0, 'arena.comments')
            ->assertJsonPath('arena.reactions.totals.fire', 1)
            ->assertJsonPath('arena.polls.0.options.0.votes_count', 1);

        // Staff moderation requires MFA step-up and support/editor role.
        $moderator = $this->staff('support');
        $adsStaff = $this->staff('ads');

        app('auth')->forgetGuards();
        $this->withToken($this->staffToken($moderator, false))
            ->postJson('/api/v1/staff/moderation/reports/'.$reportId.'/resolve', [
                'status' => 'resolved',
                'resolution_note' => 'Removed after review.',
                'comment_moderation_state' => 'removed',
            ])
            ->assertForbidden();

        app('auth')->forgetGuards();
        $this->withToken($this->staffToken($adsStaff, true))
            ->postJson('/api/v1/staff/moderation/reports/'.$reportId.'/resolve', [
                'status' => 'resolved',
                'resolution_note' => 'Ads staff cannot moderate comments.',
            ])
            ->assertForbidden();

        app('auth')->forgetGuards();
        $this->withToken($this->staffToken($moderator, true))
            ->postJson('/api/v1/staff/moderation/reports/'.$reportId.'/resolve', [
                'status' => 'resolved',
                'resolution_note' => 'Comment removed for harassment violation.',
                'comment_moderation_state' => 'removed',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', 'resolved');

        $this->assertDatabaseHas('arena_comments', [
            'id' => $bobCommentId,
            'moderation_state' => 'removed',
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $moderator->id,
            'action' => 'moderation.report.resolved',
            'subject_type' => 'moderation_report',
            'subject_id' => $reportId,
        ]);

        // Moderator triggers emergency arena lockdown; reader comments are now rejected with 409.
        $this->patchJson('/api/v1/staff/moderation/arenas/'.$ctx['arenaId'].'/control', [
            'state' => 'suspended',
            'posting_enabled' => false,
            'slow_mode_seconds' => 30,
            'emergency_lock_reason' => 'High-incident derby moderation pause.',
        ])->assertOk()->assertJsonPath('arena.state', 'suspended');

        $this->assertDatabaseHas('security_audit_events', [
            'staff_member_id' => $moderator->id,
            'action' => 'moderation.arena.control_updated',
            'subject_type' => 'match_arena',
            'subject_id' => $ctx['arenaId'],
        ]);

        app('auth')->forgetGuards();
        $this->withToken($this->readerToken($alice))
            ->postJson('/api/v1/matches/'.$ctx['fixtureId'].'/arena/comments', [
                'section' => 'neutral',
                'body' => 'Trying to post while arena is suspended.',
            ])
            ->assertStatus(409);
    }

    public function test_prediction_lock_window_deterministic_settlement_var_recompute_and_normalized_rankings(): void
    {
        DB::table('prediction_policies')->insert([
            'version_code' => PredictionScoringEngine::DEFAULT_POLICY_VERSION,
            'outcome_points' => 3,
            'exact_score_points' => 5,
            'goal_range_points' => 2,
            'first_scorer_team_points' => 2,
            'min_club_ranking_sample_size' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ctx = $this->createFixtureAndArena(fixtureStatus: 'scheduled', startsInMinutes: 30);
        $fanOne = $this->reader('Analyst One');
        $fanTwo = $this->reader('Analyst Two');
        $fanThree = $this->reader('Solo Club Fan');

        // FanOne and FanTwo support HomeTeam (2 supporters >= min_club_ranking_sample_size 2).
        // FanThree supports AwayTeam (1 supporter < min_club_ranking_sample_size 2).
        DB::table('reader_favorite_teams')->insert([
            ['user_id' => $fanOne->id, 'team_id' => $ctx['homeTeam']->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $fanTwo->id, 'team_id' => $ctx['homeTeam']->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $fanThree->id, 'team_id' => $ctx['awayTeam']->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Reject any wagering/stake payload or inconsistent score/outcome combination.
        $this->withToken($this->readerToken($fanOne))
            ->putJson('/api/v1/matches/'.$ctx['fixtureId'].'/prediction', [
                'predicted_outcome' => 'home',
                'predicted_home_score' => 2,
                'predicted_away_score' => 1,
                'predicted_goal_range' => '2_3',
                'predicted_first_scoring_team' => 'home',
                'stake' => 100,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stake');

        $this->putJson('/api/v1/matches/'.$ctx['fixtureId'].'/prediction', [
            'predicted_outcome' => 'draw',
            'predicted_home_score' => 2,
            'predicted_away_score' => 1,
            'predicted_goal_range' => '2_3',
            'predicted_first_scoring_team' => 'home',
        ])->assertUnprocessable()->assertJsonValidationErrors('predicted_outcome');

        // Valid predictions before kick-off succeed.
        // FanOne predicts 2-1 Home, 2_3 goals, Home scores first.
        $this->putJson('/api/v1/matches/'.$ctx['fixtureId'].'/prediction', [
            'predicted_outcome' => 'home',
            'predicted_home_score' => 2,
            'predicted_away_score' => 1,
            'predicted_goal_range' => '2_3',
            'predicted_first_scoring_team' => 'home',
        ])->assertOk()->assertJsonPath('prediction.predicted_home_score', 2);

        // FanTwo predicts 1-0 Home, 0_1 goals, Home scores first.
        app('auth')->forgetGuards();
        $this->withToken($this->readerToken($fanTwo))
            ->putJson('/api/v1/matches/'.$ctx['fixtureId'].'/prediction', [
                'predicted_outcome' => 'home',
                'predicted_home_score' => 1,
                'predicted_away_score' => 0,
                'predicted_goal_range' => '0_1',
                'predicted_first_scoring_team' => 'home',
            ])->assertOk();

        // FanThree predicts 2-1 Home, 2_3 goals, Home scores first.
        app('auth')->forgetGuards();
        $this->withToken($this->readerToken($fanThree))
            ->putJson('/api/v1/matches/'.$ctx['fixtureId'].'/prediction', [
                'predicted_outcome' => 'home',
                'predicted_home_score' => 2,
                'predicted_away_score' => 1,
                'predicted_goal_range' => '2_3',
                'predicted_first_scoring_team' => 'home',
            ])->assertOk();

        // Once fixture starts_at passes or status becomes live, predictions are locked (409 Conflict).
        DB::table('fixtures')->where('id', $ctx['fixtureId'])->update([
            'status' => 'live',
            'starts_at' => now()->subMinutes(5),
        ]);

        $this->putJson('/api/v1/matches/'.$ctx['fixtureId'].'/prediction', [
            'predicted_outcome' => 'away',
            'predicted_home_score' => 0,
            'predicted_away_score' => 3,
            'predicted_goal_range' => '2_3',
            'predicted_first_scoring_team' => 'away',
        ])->assertStatus(409);

        // Official final result revision 1: Home 2 - 1 Away, first scorer: home.
        DB::table('fixtures')->where('id', $ctx['fixtureId'])->update([
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
            'first_scoring_team' => 'home',
            'source_revision' => 1,
        ]);

        $settled = app(PredictionScoringEngine::class)->settleFixture($ctx['fixtureId']);
        $this->assertSame(3, $settled);

        // FanOne hit outcome (3) + exact score (5) + goal range (2) + first scorer (2) = 12 pts!
        // FanTwo hit outcome (3) + first scorer (2) = 5 pts!
        app('auth')->forgetGuards();
        $this->withToken($this->readerToken($fanOne))
            ->getJson('/api/v1/matches/'.$ctx['fixtureId'].'/prediction')
            ->assertOk()
            ->assertJsonPath('prediction.settlement.total_points', 12)
            ->assertJsonPath('prediction.settlement.is_exact_score_hit', true);

        // Check Individual Leaderboard and Club Leaderboard (HomeTeam has 2 qualified supporters >= 2, so it ranks #1 ahead of 1-supporter AwayTeam!).
        $this->getJson('/api/v1/rankings/users')
            ->assertOk()
            ->assertJsonPath('rankings.0.user_id', $fanOne->id)
            ->assertJsonPath('rankings.0.total_points', 12)
            ->assertJsonPath('rankings.0.badges.0', 'score_sniper');

        $this->getJson('/api/v1/rankings/clubs')
            ->assertOk()
            ->assertJsonPath('club_rankings.0.team_id', $ctx['homeTeam']->id)
            ->assertJsonPath('club_rankings.0.is_qualified_for_official_rank', true)
            ->assertJsonPath('club_rankings.1.team_id', $ctx['awayTeam']->id)
            ->assertJsonPath('club_rankings.1.is_qualified_for_official_rank', false);

        // Official post-match VAR correction (revision 2): Home 1 - 0 Away!
        DB::table('fixtures')->where('id', $ctx['fixtureId'])->update([
            'home_score' => 1,
            'away_score' => 0,
            'first_scoring_team' => 'home',
            'source_revision' => 2,
        ]);

        app(PredictionScoringEngine::class)->settleFixture($ctx['fixtureId']);

        // Now FanTwo has the exact 1-0 score (12 pts) and FanOne has 5 pts on revision 2!
        $this->getJson('/api/v1/rankings/users')
            ->assertOk()
            ->assertJsonPath('rankings.0.user_id', $fanTwo->id)
            ->assertJsonPath('rankings.0.total_points', 12);
    }
}
