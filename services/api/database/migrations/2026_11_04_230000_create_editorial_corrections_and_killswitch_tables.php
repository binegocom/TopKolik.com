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
        // 1. Editorial Corrections History (P06-009)
        Schema::create('editorial_corrections_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('publication_record_id')->constrained('editorial_publication_records')->cascadeOnDelete();
            $table->unsignedInteger('previous_revision');
            $table->unsignedInteger('new_revision');
            $table->string('correction_type', 40);
            $table->text('reason');
            $table->json('previous_snapshot');
            $table->json('new_snapshot');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['publication_record_id', 'new_revision'], 'editorial_corrections_pub_rev_idx');
        });

        // 2. Emergency Editorial Kill Switch (P06-012)
        Schema::create('editorial_kill_switches', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 24);
            $table->string('target', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('reason');
            $table->unsignedBigInteger('engaged_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->index(['scope', 'target', 'is_active'], 'editorial_kill_switch_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE editorial_kill_switches
    ADD CONSTRAINT editorial_kill_switch_scope_chk
    CHECK (scope IN ('global', 'sport', 'provider'))
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_kill_switches');
        Schema::dropIfExists('editorial_corrections_history');
    }
};

