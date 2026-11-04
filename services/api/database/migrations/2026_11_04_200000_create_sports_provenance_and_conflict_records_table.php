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
        Schema::create('sports_provenance_records', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_name', 40);
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->json('observation_snapshot');
            $table->unsignedInteger('source_revision')->default(1);
            $table->string('conflict_status', 24)->default('clean');
            $table->text('conflict_reason')->nullable();
            $table->timestampTz('license_expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['entity_type', 'entity_id', 'conflict_status'], 'provenance_entity_status_idx');
            $table->index(['provider_name', 'source_revision'], 'provenance_provider_rev_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE sports_provenance_records
    ADD CONSTRAINT provenance_conflict_status_chk
    CHECK (conflict_status IN ('clean', 'hold', 'resolved', 'overridden'))
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_provenance_records');
    }
};
