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
        Schema::create('security_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_member_id')->constrained('staff_members')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['staff_member_id', 'id'], 'audit_staff_sequence_idx');
            $table->index(['action', 'id'], 'audit_action_sequence_idx');
        });

        // Application writes are insert-only. Database owners and superusers can
        // still drop triggers; production requires separate DB privileges and
        // external log shipping for protection against privileged tampering.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_security_audit_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'security audit events are append-only';
END;
$$;
CREATE TRIGGER security_audit_reject_change
BEFORE UPDATE OR DELETE ON security_audit_events
FOR EACH ROW EXECUTE FUNCTION reject_security_audit_mutation();
CREATE TRIGGER security_audit_reject_truncate
BEFORE TRUNCATE ON security_audit_events
FOR EACH STATEMENT EXECUTE FUNCTION reject_security_audit_mutation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only audit ledger migration: approved remediation required.');
    }
};
