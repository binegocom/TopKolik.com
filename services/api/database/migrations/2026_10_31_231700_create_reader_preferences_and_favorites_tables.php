<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Empty canonical lookup tables. No sports/team records are seeded until P04.
        Schema::create('sports', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 200);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sport_id')->constrained('sports')->restrictOnDelete();
            $table->string('slug', 120);
            $table->string('name', 200);
            $table->timestamps();
            $table->unique(['sport_id', 'slug']);
        });

        Schema::create('reader_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('preferred_locale', 2)->nullable();
            $table->boolean('personalized_news_enabled')->default(false);
            $table->boolean('analytics_consent')->default(false);
            $table->timestamps();
        });

        Schema::create('reader_favorite_sports', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sport_id')->constrained('sports')->cascadeOnDelete();
            $table->primary(['user_id', 'sport_id']);
            $table->timestamps();
        });

        Schema::create('reader_favorite_teams', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->primary(['user_id', 'team_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // This migration introduces data-bearing tables. Never silently destroy
        // reader preferences, favorites or sports catalog records during rollback.
        throw new RuntimeException('Forward-only migration: a reviewed, non-destructive remediation is required.');
    }
};
