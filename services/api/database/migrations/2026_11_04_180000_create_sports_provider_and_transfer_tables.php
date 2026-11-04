<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Provider Entity Mappings (P04-002)
        Schema::create('provider_entity_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_name', 40); // e.g. 'sportradar', 'opta', 'api-football'
            $table->string('entity_type', 32);  // 'sport', 'competition', 'team', 'athlete', 'venue', 'fixture'
            $table->string('provider_entity_id', 120);
            $table->unsignedBigInteger('entity_id');
            $table->string('raw_payload_checksum', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['provider_name', 'entity_type', 'provider_entity_id'],
                'provider_entity_mapping_unique'
            );
            $table->index(['entity_type', 'entity_id'], 'entity_mapping_lookup_idx');
        });

        // 2. Athlete Multi-Team Transfer History (P04-002)
        Schema::create('athlete_team_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('from_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('to_team_id')->constrained('teams')->cascadeOnDelete();
            $table->date('transfer_date');
            $table->string('transfer_type', 24)->default('permanent'); // permanent, loan, free
            $table->foreignId('season_id')->nullable()->constrained('seasons')->nullOnDelete();
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->index(['athlete_id', 'transfer_date'], 'athlete_transfers_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_team_transfers');
        Schema::dropIfExists('provider_entity_mappings');
    }
};

