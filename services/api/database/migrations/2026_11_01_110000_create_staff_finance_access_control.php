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
        Schema::create('staff_finance_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_member_id')->unique()->constrained('staff_members')->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->foreignId('last_approved_by_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('staff_finance_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_staff_member_id')->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('requested_by_staff_member_id')->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('reviewed_by_staff_member_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->uuid('requested_request_id')->nullable();
            $table->uuid('decided_request_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['status', 'id'], 'finance_access_requests_queue_idx');
            $table->index(['target_staff_member_id', 'id'], 'finance_access_requests_target_idx');
        });

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX finance_access_one_pending_per_target
ON staff_finance_access_requests (target_staff_member_id)
WHERE status = 'pending'
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE staff_finance_access_requests ADD CONSTRAINT finance_access_state_check
CHECK (
    (status = 'pending' AND reviewed_by_staff_member_id IS NULL
        AND decided_at IS NULL AND decided_request_id IS NULL)
    OR
    (status IN ('approved', 'rejected') AND reviewed_by_staff_member_id IS NOT NULL
        AND decided_at IS NOT NULL)
)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE staff_finance_access_requests ADD CONSTRAINT finance_access_separation_check
CHECK (
    target_staff_member_id <> requested_by_staff_member_id
    AND (reviewed_by_staff_member_id IS NULL OR
        (reviewed_by_staff_member_id <> requested_by_staff_member_id
            AND reviewed_by_staff_member_id <> target_staff_member_id))
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_finance_access_request_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'pending' OR NEW.status NOT IN ('approved', 'rejected')
       OR OLD.target_staff_member_id IS DISTINCT FROM NEW.target_staff_member_id
       OR OLD.requested_by_staff_member_id IS DISTINCT FROM NEW.requested_by_staff_member_id
       OR OLD.requested_request_id IS DISTINCT FROM NEW.requested_request_id
       OR OLD.expires_at IS DISTINCT FROM NEW.expires_at
       OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
        RAISE EXCEPTION 'finance access decision is immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER finance_access_request_transition
BEFORE UPDATE ON staff_finance_access_requests
FOR EACH ROW EXECUTE FUNCTION guard_finance_access_request_mutation();
CREATE OR REPLACE FUNCTION reject_finance_access_request_removal() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'finance access request records are append-only';
END;
$$;
CREATE TRIGGER finance_access_request_no_delete
BEFORE DELETE ON staff_finance_access_requests
FOR EACH ROW EXECUTE FUNCTION reject_finance_access_request_removal();
CREATE TRIGGER finance_access_request_no_truncate
BEFORE TRUNCATE ON staff_finance_access_requests
FOR EACH STATEMENT EXECUTE FUNCTION reject_finance_access_request_removal();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only finance access decision migration: retention and backup authorization required.');
    }
};
