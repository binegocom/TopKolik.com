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
        // 1. Deterministic Story Signals (P06-001)
        Schema::create('editorial_story_signals', function (Blueprint $table): void {
            $table->id();
            $table->string('signal_type', 32);
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('provider_name', 40);
            $table->unsignedBigInteger('provenance_record_id')->nullable();
            $table->string('status', 24)->default('emitted');
            $table->json('payload');
            $table->timestampTz('emitted_at');
            $table->timestampsTz();

            $table->index(['signal_type', 'status'], 'story_signals_type_status_idx');
            $table->index(['entity_type', 'entity_id'], 'story_signals_entity_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE editorial_story_signals
    ADD CONSTRAINT story_signals_type_chk
    CHECK (signal_type IN ('final_match_result', 'fixture_change', 'official_statement', 'transfer_announcement')),
    ADD CONSTRAINT story_signals_status_chk
    CHECK (status IN ('emitted', 'held', 'consumed', 'rejected'))
SQL);

        // 2. Evidence-Bound Fact Packs (P06-002)
        Schema::create('editorial_fact_packs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_signal_id')->constrained('editorial_story_signals')->cascadeOnDelete();
            $table->string('schema_version', 16)->default('1.0.0');
            $table->string('licensed_source_ref', 120);
            $table->timestampTz('license_expires_at');
            $table->json('metric_facts');
            $table->json('evidence_claims');
            $table->boolean('is_verified')->default(true);
            $table->timestampsTz();

            $table->index(['story_signal_id'], 'fact_packs_signal_idx');
            $table->index(['licensed_source_ref'], 'fact_packs_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_fact_packs');
        Schema::dropIfExists('editorial_story_signals');
    }
};

