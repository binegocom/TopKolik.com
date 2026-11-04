<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Canonical competition, season and fixture catalog tables remain
        // initially empty in application runtime until licensed ingestion (P04).
        Schema::create('competitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sport_id')->constrained('sports')->restrictOnDelete();
            $table->string('slug', 120);
            $table->string('name', 200);
            $table->string('country_code', 2)->nullable();
            $table->string('provider_ref', 120)->nullable();
            $table->timestamps();

            $table->unique(['sport_id', 'slug']);
        });

        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions')->restrictOnDelete();
            $table->string('label', 60);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['competition_id', 'label']);
        });

        Schema::create('fixtures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions')->restrictOnDelete();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('home_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->restrictOnDelete();
            $table->string('status', 24)->default('scheduled');
            $table->timestampTz('starts_at');
            $table->smallInteger('home_score')->nullable();
            $table->smallInteger('away_score')->nullable();
            $table->string('first_scoring_team', 12)->nullable();
            $table->unsignedInteger('source_revision')->default(1);
            $table->timestamps();

            $table->index(['competition_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE fixtures
    ADD CONSTRAINT fixtures_distinct_teams_chk CHECK (home_team_id <> away_team_id),
    ADD CONSTRAINT fixtures_status_chk CHECK (status IN ('scheduled', 'live', 'finished', 'postponed', 'cancelled')),
    ADD CONSTRAINT fixtures_first_scoring_team_chk CHECK (first_scoring_team IS NULL OR first_scoring_team IN ('home', 'away', 'none')),
    ADD CONSTRAINT fixtures_scores_non_negative_chk CHECK ((home_score IS NULL OR home_score >= 0) AND (away_score IS NULL OR away_score >= 0)),
    ADD CONSTRAINT fixtures_source_revision_chk CHECK (source_revision >= 1)
SQL);

        Schema::create('match_arenas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->unique()->constrained('fixtures')->restrictOnDelete();
            $table->string('state', 24)->default('scheduled');
            $table->boolean('posting_enabled')->default(true);
            $table->unsignedSmallInteger('slow_mode_seconds')->default(0);
            $table->foreignId('emergency_locked_by_staff_id')->nullable()->constrained('staff_members')->nullOnDelete();
            $table->string('emergency_lock_reason', 255)->nullable();
            $table->timestampTz('emergency_locked_at')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
ALTER TABLE match_arenas
    ADD CONSTRAINT match_arenas_state_chk CHECK (state IN ('scheduled', 'pre_match_open', 'live_open', 'post_match_open', 'archived', 'suspended')),
    ADD CONSTRAINT match_arenas_slow_mode_chk CHECK (slow_mode_seconds >= 0 AND slow_mode_seconds <= 3600)
SQL);

        Schema::create('arena_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_arena_id')->constrained('match_arenas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_comment_id')->nullable()->constrained('arena_comments')->restrictOnDelete();
            $table->string('section', 16);
            $table->text('body');
            $table->string('moderation_state', 24)->default('visible');
            $table->string('moderation_reason', 120)->nullable();
            $table->foreignId('moderated_by_staff_id')->nullable()->constrained('staff_members')->nullOnDelete();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['match_arena_id', 'section', 'moderation_state', 'id'], 'arena_comments_feed_idx');
            $table->index(['match_arena_id', 'user_id', 'created_at'], 'arena_comments_slowmode_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE arena_comments
    ADD CONSTRAINT arena_comments_section_chk CHECK (section IN ('home', 'away', 'neutral')),
    ADD CONSTRAINT arena_comments_moderation_state_chk CHECK (moderation_state IN ('visible', 'pending_review', 'quarantined', 'hidden', 'removed')),
    ADD CONSTRAINT arena_comments_body_len_chk CHECK (char_length(btrim(body)) >= 1 AND char_length(body) <= 1000)
SQL);

        Schema::create('arena_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_arena_id')->constrained('match_arenas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reaction_type', 24);
            $table->timestamps();

            $table->unique(['match_arena_id', 'user_id']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE arena_reactions
    ADD CONSTRAINT arena_reactions_type_chk CHECK (reaction_type IN ('fire', 'goal_hype', 'tactical_applause', 'shock', 'heartbreak', 'whistle_protest'))
SQL);

        Schema::create('arena_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_arena_id')->constrained('match_arenas')->cascadeOnDelete();
            $table->string('question_key', 120);
            $table->string('question_text', 240);
            $table->boolean('is_closed')->default(false);
            $table->timestampTz('closes_at')->nullable();
            $table->timestamps();

            $table->unique(['match_arena_id', 'question_key']);
        });

        Schema::create('arena_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('arena_poll_id')->constrained('arena_polls')->cascadeOnDelete();
            $table->string('option_key', 40);
            $table->string('label', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['arena_poll_id', 'option_key']);
        });

        Schema::create('arena_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('arena_poll_id')->constrained('arena_polls')->cascadeOnDelete();
            $table->foreignId('arena_poll_option_id')->constrained('arena_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['arena_poll_id', 'user_id']);
        });

        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['blocker_user_id', 'blocked_user_id']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE user_blocks
    ADD CONSTRAINT user_blocks_no_self_block_chk CHECK (blocker_user_id <> blocked_user_id)
SQL);

        Schema::create('moderation_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('arena_comment_id')->constrained('arena_comments')->cascadeOnDelete();
            $table->string('reason_category', 32);
            $table->string('details', 500)->nullable();
            $table->string('status', 24)->default('open');
            $table->foreignId('resolved_by_staff_id')->nullable()->constrained('staff_members')->nullOnDelete();
            $table->string('resolution_note', 255)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['reporter_user_id', 'arena_comment_id']);
            $table->index(['status', 'id']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE moderation_reports
    ADD CONSTRAINT moderation_reports_reason_chk CHECK (reason_category IN ('abuse', 'hate_speech', 'harassment', 'spam', 'misinformation', 'impersonation', 'illegal_content')),
    ADD CONSTRAINT moderation_reports_status_chk CHECK (status IN ('open', 'resolved', 'dismissed'))
SQL);

        Schema::create('prediction_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('version_code', 60)->unique();
            $table->unsignedSmallInteger('outcome_points')->default(3);
            $table->unsignedSmallInteger('exact_score_points')->default(5);
            $table->unsignedSmallInteger('goal_range_points')->default(2);
            $table->unsignedSmallInteger('first_scorer_team_points')->default(2);
            $table->unsignedSmallInteger('min_club_ranking_sample_size')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
ALTER TABLE prediction_policies
    ADD CONSTRAINT prediction_policies_points_chk CHECK (
        outcome_points > 0
        AND exact_score_points > 0
        AND goal_range_points >= 0
        AND first_scorer_team_points >= 0
        AND min_club_ranking_sample_size >= 1
    )
SQL);

        Schema::create('match_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->constrained('fixtures')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('policy_version', 60);
            $table->string('predicted_outcome', 12);
            $table->unsignedSmallInteger('predicted_home_score');
            $table->unsignedSmallInteger('predicted_away_score');
            $table->string('predicted_goal_range', 16);
            $table->string('predicted_first_scoring_team', 12);
            $table->timestampTz('submitted_at');
            $table->timestamps();

            $table->unique(['fixture_id', 'user_id']);
            $table->index(['user_id', 'submitted_at']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE match_predictions
    ADD CONSTRAINT match_predictions_outcome_chk CHECK (predicted_outcome IN ('home', 'draw', 'away')),
    ADD CONSTRAINT match_predictions_scores_chk CHECK (predicted_home_score <= 25 AND predicted_away_score <= 25),
    ADD CONSTRAINT match_predictions_goal_range_chk CHECK (predicted_goal_range IN ('0_1', '2_3', '4_plus')),
    ADD CONSTRAINT match_predictions_first_scorer_chk CHECK (predicted_first_scoring_team IN ('home', 'away', 'none'))
SQL);

        Schema::create('prediction_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_prediction_id')->unique()->constrained('match_predictions')->cascadeOnDelete();
            $table->foreignId('fixture_id')->constrained('fixtures')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('policy_version', 60);
            $table->unsignedSmallInteger('outcome_points_awarded');
            $table->unsignedSmallInteger('exact_score_points_awarded');
            $table->unsignedSmallInteger('goal_range_points_awarded');
            $table->unsignedSmallInteger('first_scorer_points_awarded');
            $table->unsignedSmallInteger('total_points');
            $table->boolean('is_exact_score_hit');
            $table->boolean('is_outcome_hit');
            $table->unsignedInteger('fixture_source_revision');
            $table->timestampTz('settled_at');
            $table->timestamps();

            $table->index(['user_id', 'total_points']);
            $table->index(['fixture_id', 'fixture_source_revision']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: a reviewed, non-destructive remediation is required.');
    }
};
