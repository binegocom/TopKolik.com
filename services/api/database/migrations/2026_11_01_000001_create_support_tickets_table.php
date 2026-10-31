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
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assigned_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->text('subject');
            $table->text('message');
            $table->string('status', 24)->default('open');
            $table->timestampsTz();

            $table->index(['assigned_staff_id', 'status', 'id'], 'support_tickets_assignment_status_idx');
        });

        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_ticket_status_check CHECK (status IN ('open','pending','resolved'))");
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only support ticket migration: reviewed remediation required.');
    }
};
