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
        // 1. Stadiums & Venues
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name', 200);
            $table->string('city', 120);
            $table->string('country_code', 2)->default('TR');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('surface', 60)->nullable();
            $table->timestamps();
        });

        // 2. Add venue & branding attributes to teams
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('short_name', 60)->nullable()->after('name');
            $table->string('logo_url', 500)->nullable()->after('short_name');
            $table->foreignId('venue_id')->nullable()->after('logo_url')->constrained('venues')->nullOnDelete();
            $table->string('primary_color', 10)->nullable()->after('venue_id');
            $table->string('secondary_color', 10)->nullable()->after('primary_color');
        });

        // 3. Athletes / Footballers / Players
        Schema::create('athletes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sport_id')->constrained('sports')->restrictOnDelete();
            $table->foreignId('current_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('slug', 140)->unique();
            $table->string('name', 200);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('position', 20);
            $table->unsignedSmallInteger('shirt_number')->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->string('preferred_foot', 10)->nullable();
            $table->string('photo_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['sport_id', 'current_team_id']);
            $table->index(['name', 'slug']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE athletes
    ADD CONSTRAINT athlete_position_chk CHECK (position IN ('GK', 'DF', 'MF', 'FW')),
    ADD CONSTRAINT athlete_preferred_foot_chk CHECK (preferred_foot IS NULL OR preferred_foot IN ('left', 'right', 'both'))
SQL);

        // 4. Athlete Season Statistics
        Schema::create('athlete_season_statistics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('appearances')->default(0);
            $table->unsignedInteger('minutes_played')->default(0);
            $table->unsignedSmallInteger('goals')->default(0);
            $table->unsignedSmallInteger('assists')->default(0);
            $table->unsignedSmallInteger('yellow_cards')->default(0);
            $table->unsignedSmallInteger('red_cards')->default(0);
            $table->decimal('rating', 4, 2)->nullable();
            $table->timestamps();

            $table->unique(['athlete_id', 'season_id', 'team_id'], 'athlete_season_stats_unique');
        });

        // 5. User Follows (teams, competitions, athletes)
        Schema::create('user_follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('followable_type', 32);
            $table->unsignedBigInteger('followable_id');
            $table->boolean('notify_match_start')->default(true);
            $table->boolean('notify_goals')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'followable_type', 'followable_id'], 'user_follows_unique');
            $table->index(['followable_type', 'followable_id'], 'user_follows_target_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE user_follows
    ADD CONSTRAINT user_follows_type_chk CHECK (followable_type IN ('team', 'competition', 'athlete'))
SQL);

        // 6. User Saved / Bookmarked Items (fixtures, articles, arena comments)
        Schema::create('user_saved_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_type', 32);
            $table->unsignedBigInteger('item_id');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'item_type', 'item_id'], 'user_saved_items_unique');
            $table->index(['item_type', 'item_id'], 'user_saved_items_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE user_saved_items
    ADD CONSTRAINT user_saved_items_type_chk CHECK (item_type IN ('fixture', 'article', 'arena_comment'))
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_saved_items');
        Schema::dropIfExists('user_follows');
        Schema::dropIfExists('athlete_season_statistics');
        Schema::dropIfExists('athletes');

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropForeign(['venue_id']);
            $table->dropColumn(['short_name', 'logo_url', 'venue_id', 'primary_color', 'secondary_color']);
        });

        Schema::dropIfExists('venues');
    }
};
