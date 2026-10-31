<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')
                ->constrained('support_tickets')->restrictOnDelete();
            $table->foreignId('staff_member_id')
                ->constrained('staff_members')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('message_fingerprint', 64);
            $table->text('message');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['support_ticket_id', 'idempotency_key'], 'support_reply_idempotency_unique');
            $table->index(['support_ticket_id', 'id'], 'support_reply_ticket_index');
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->timestampTz('escalated_at')->nullable();
            $table->foreignId('escalated_by_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only support conversation migration: reviewed remediation required.');
    }
};
