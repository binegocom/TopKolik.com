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
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->foreignId('escalation_reviewer_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->timestampTz('escalation_reviewed_at')->nullable();
            $table->foreignId('escalation_reviewed_by_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->text('escalation_review_note')->nullable();
            $table->index(['escalation_reviewer_staff_id', 'status', 'id'], 'support_escalation_reviewer_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE support_tickets ADD CONSTRAINT support_escalation_review_separation_check
CHECK (
    escalation_reviewer_staff_id IS NULL
    OR (
        (assigned_staff_id IS NULL OR escalation_reviewer_staff_id <> assigned_staff_id)
        AND (escalated_by_staff_id IS NULL OR escalation_reviewer_staff_id <> escalated_by_staff_id)
    )
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE support_tickets ADD CONSTRAINT support_escalation_review_state_check
CHECK (
    (escalation_reviewed_at IS NULL AND escalation_reviewed_by_staff_id IS NULL
        AND escalation_review_note IS NULL)
    OR
    (escalated_at IS NOT NULL AND escalation_reviewer_staff_id IS NOT NULL
        AND escalation_reviewed_at IS NOT NULL
        AND escalation_reviewed_by_staff_id = escalation_reviewer_staff_id
        AND escalation_review_note IS NOT NULL)
)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only support escalation review migration: reviewed remediation required.');
    }
};
