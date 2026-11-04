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
        // 1. Match Timeline & Events (P05-002)
        Schema::create('fixture_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->constrained('fixtures')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('player_name', 120)->nullable();
            $table->unsignedSmallInteger('minute');
            $table->unsignedSmallInteger('extra_minute')->nullable();
            $table->string('period', 16)->default('1h');
            $table->string('event_type', 24);
            $table->string('detail', 255)->nullable();
            $table->boolean('is_var_cancelled')->default(false);
            $table->string('source_provenance', 120)->default('tff:official');
            $table->timestamps();

            $table->index(['fixture_id', 'minute', 'id'], 'fixture_events_timeline_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE fixture_events
    ADD CONSTRAINT fixture_events_minute_chk CHECK (minute >= 1 AND minute <= 130),
    ADD CONSTRAINT fixture_events_period_chk CHECK (period IN ('1h', '2h', 'et1', 'et2', 'penalties')),
    ADD CONSTRAINT fixture_events_type_chk CHECK (event_type IN ('goal', 'own_goal', 'penalty_goal', 'missed_penalty', 'yellow_card', 'red_card', 'yellow_red_card', 'substitution', 'var_decision'))
SQL);

        // 2. Team Statistics (P05-002)
        Schema::create('fixture_team_statistics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->constrained('fixtures')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->decimal('possession_pct', 4, 1)->default(50.0);
            $table->unsignedSmallInteger('total_shots')->default(0);
            $table->unsignedSmallInteger('shots_on_target')->default(0);
            $table->unsignedSmallInteger('shots_off_target')->default(0);
            $table->unsignedSmallInteger('blocked_shots')->default(0);
            $table->unsignedSmallInteger('corner_kicks')->default(0);
            $table->unsignedSmallInteger('offsides')->default(0);
            $table->unsignedSmallInteger('fouls')->default(0);
            $table->unsignedSmallInteger('yellow_cards')->default(0);
            $table->unsignedSmallInteger('red_cards')->default(0);
            $table->unsignedSmallInteger('passes_completed')->default(0);
            $table->unsignedSmallInteger('passes_total')->default(0);
            $table->decimal('pass_accuracy_pct', 4, 1)->default(0.0);
            $table->decimal('expected_goals_xg', 4, 2)->default(0.0);
            $table->unsignedInteger('source_revision')->default(1);
            $table->timestamps();

            $table->unique(['fixture_id', 'team_id'], 'fixture_team_stats_unique');
        });

        DB::statement(<<<'SQL'
ALTER TABLE fixture_team_statistics
    ADD CONSTRAINT fixture_stats_possession_chk CHECK (possession_pct >= 0.0 AND possession_pct <= 100.0),
    ADD CONSTRAINT fixture_stats_pass_acc_chk CHECK (pass_accuracy_pct >= 0.0 AND pass_accuracy_pct <= 100.0),
    ADD CONSTRAINT fixture_stats_xg_chk CHECK (expected_goals_xg >= 0.0)
SQL);

        // 3. Lineups and Formations (P05-003)
        Schema::create('fixture_lineups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->constrained('fixtures')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->string('formation', 16)->default('4-2-3-1');
            $table->string('coach_name', 120)->nullable();
            $table->timestamps();

            $table->unique(['fixture_id', 'team_id'], 'fixture_lineups_unique');
        });

        Schema::create('fixture_lineup_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_lineup_id')->constrained('fixture_lineups')->cascadeOnDelete();
            $table->string('player_name', 120);
            $table->unsignedSmallInteger('shirt_number');
            $table->string('position', 16);
            $table->unsignedSmallInteger('grid_row')->default(1);
            $table->unsignedSmallInteger('grid_col')->default(1);
            $table->boolean('is_starter')->default(true);
            $table->boolean('is_captain')->default(false);
            $table->timestamps();

            $table->index(['fixture_lineup_id', 'is_starter', 'shirt_number'], 'fixture_lineup_players_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE fixture_lineup_players
    ADD CONSTRAINT lineup_player_position_chk CHECK (position IN ('GK', 'DF', 'MF', 'FW')),
    ADD CONSTRAINT lineup_player_shirt_chk CHECK (shirt_number >= 1 AND shirt_number <= 99)
SQL);

        // 4. Standings & League Rankings (P05-003)
        Schema::create('competition_standings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions')->restrictOnDelete();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('played')->default(0);
            $table->unsignedSmallInteger('won')->default(0);
            $table->unsignedSmallInteger('drawn')->default(0);
            $table->unsignedSmallInteger('lost')->default(0);
            $table->unsignedSmallInteger('goals_for')->default(0);
            $table->unsignedSmallInteger('goals_against')->default(0);
            $table->smallInteger('goal_difference')->default(0);
            $table->unsignedSmallInteger('points')->default(0);
            $table->string('form', 10)->default('WWDDD');
            $table->string('source_provenance', 120)->default('official:table');
            $table->timestamps();

            $table->unique(['competition_id', 'season_id', 'team_id'], 'competition_standings_unique');
            $table->index(['competition_id', 'season_id', 'position'], 'competition_standings_pos_idx');
        });

        // 5. Live Voice Tribune Rooms (Social Fan Experience)
        Schema::create('voice_tribune_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->constrained('fixtures')->cascadeOnDelete();
            $table->foreignId('club_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('phase_tag', 32)->default('MAÇ ANINDA CANLI');
            $table->unsignedInteger('listeners_count')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['fixture_id', 'is_active'], 'voice_tribunes_fixture_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_tribune_rooms');
        Schema::dropIfExists('competition_standings');
        Schema::dropIfExists('fixture_lineup_players');
        Schema::dropIfExists('fixture_lineups');
        Schema::dropIfExists('fixture_team_statistics');
        Schema::dropIfExists('fixture_events');
    }
};
