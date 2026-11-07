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
        // 1. Editorial Articles Extension / Canonical Publishing Table (P06-008)
        Schema::create('editorial_publication_records', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_event_key', 128)->index();
            $table->foreignId('story_signal_id')->constrained('editorial_story_signals')->restrictOnDelete();
            $table->foreignId('fact_pack_id')->constrained('editorial_fact_packs')->restrictOnDelete();
            $table->string('headline', 255);
            $table->text('summary');
            $table->json('paragraphs');
            $table->json('cited_claim_ids');
            $table->string('publishing_state', 24)->default('draft');
            $table->string('quarantine_reason', 255)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('idempotency_key', 64)->unique();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['publishing_state', 'published_at'], 'editorial_pub_state_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE editorial_publication_records
    ADD CONSTRAINT editorial_pub_state_chk
    CHECK (publishing_state IN ('draft', 'validated', 'quarantined', 'approved', 'published', 'retracted'))
SQL);

        // 2. Transactional Outbox for Syndication, Push, and Cache Purge (P06-008)
        Schema::create('editorial_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('publication_record_id')->constrained('editorial_publication_records')->cascadeOnDelete();
            $table->string('event_name', 48);
            $table->json('payload');
            $table->string('delivery_status', 24)->default('pending');
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['delivery_status', 'created_at'], 'editorial_outbox_status_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE editorial_outbox
    ADD CONSTRAINT editorial_outbox_status_chk
    CHECK (delivery_status IN ('pending', 'delivered', 'failed', 'dead_letter'))
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_outbox');
        Schema::dropIfExists('editorial_publication_records');
    }
};

