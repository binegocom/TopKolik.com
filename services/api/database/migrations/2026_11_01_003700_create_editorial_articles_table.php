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
        Schema::create('editorial_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_staff_id')
                ->constrained('staff_members')->restrictOnDelete();
            $table->text('title');
            $table->text('body');
            $table->string('status', 24)->default('draft');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_staff_id', 'status', 'id'], 'editorial_article_owner_state_idx');
        });

        DB::statement("ALTER TABLE editorial_articles ADD CONSTRAINT editorial_article_status_check CHECK (status IN ('draft','in_review','held','published','retracted'))");
        DB::statement("ALTER TABLE editorial_articles ADD CONSTRAINT editorial_revision_positive_check CHECK (revision >= 1)");
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only editorial migration: review data and backup before changes.');
    }
};
