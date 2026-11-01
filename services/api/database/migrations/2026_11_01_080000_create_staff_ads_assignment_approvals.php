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
        // Separate, explicit authority; no existing editor/support/ads/finance
        // role obtains administrative privileges by role name alone.
        Schema::create('staff_security_admin_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_member_id')->unique()->constrained('staff_members')->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->string('authorization_reference', 120);
            $table->timestampsTz();
        });

        Schema::create('staff_ads_assignment_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_staff_member_id')->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('advertiser_organization_id')->constrained('advertiser_organizations')->restrictOnDelete();
            $table->foreignId('requested_by_staff_member_id')->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('reviewed_by_staff_member_id')->nullable()->constrained('staff_members')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->uuid('requested_request_id')->nullable();
            $table->uuid('decided_request_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['status', 'id'], 'ads_assignment_approval_queue_idx');
            $table->index(['target_staff_member_id', 'advertiser_organization_id'], 'ads_assignment_approval_target_idx');
        });
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX ads_assignment_one_pending_per_target_org
ON staff_ads_assignment_approvals (target_staff_member_id, advertiser_organization_id)
WHERE status = 'pending'
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE staff_ads_assignment_approvals ADD CONSTRAINT ads_assignment_approval_state_check
CHECK (
    (status = 'pending' AND reviewed_by_staff_member_id IS NULL
        AND decided_at IS NULL AND decided_request_id IS NULL)
    OR
    (status IN ('approved', 'rejected') AND reviewed_by_staff_member_id IS NOT NULL
        AND decided_at IS NOT NULL)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE staff_ads_assignment_approvals ADD CONSTRAINT ads_assignment_approval_sod_check
CHECK (
    requested_by_staff_member_id <> target_staff_member_id
    AND (reviewed_by_staff_member_id IS NULL OR
        (reviewed_by_staff_member_id <> requested_by_staff_member_id
         AND reviewed_by_staff_member_id <> target_staff_member_id))
)
SQL);

        // A decision is a one-time transition. Request principals, target,
        // timestamps and request identity cannot be swapped after creation.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_ads_assignment_approval_transition() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'pending' OR NEW.status NOT IN ('approved', 'rejected')
       OR OLD.target_staff_member_id IS DISTINCT FROM NEW.target_staff_member_id
       OR OLD.advertiser_organization_id IS DISTINCT FROM NEW.advertiser_organization_id
       OR OLD.requested_by_staff_member_id IS DISTINCT FROM NEW.requested_by_staff_member_id
       OR OLD.requested_request_id IS DISTINCT FROM NEW.requested_request_id
       OR OLD.expires_at IS DISTINCT FROM NEW.expires_at
       OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
        RAISE EXCEPTION 'assignment approval transition is immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER ads_assignment_approval_guard
BEFORE UPDATE ON staff_ads_assignment_approvals
FOR EACH ROW EXECUTE FUNCTION enforce_ads_assignment_approval_transition();
CREATE OR REPLACE FUNCTION reject_ads_assignment_approval_removal() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'assignment approval records cannot be deleted';
END;
$$;
CREATE TRIGGER ads_assignment_approval_no_delete
BEFORE DELETE ON staff_ads_assignment_approvals
FOR EACH ROW EXECUTE FUNCTION reject_ads_assignment_approval_removal();
CREATE TRIGGER ads_assignment_approval_no_truncate
BEFORE TRUNCATE ON staff_ads_assignment_approvals
FOR EACH STATEMENT EXECUTE FUNCTION reject_ads_assignment_approval_removal();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only privileged approvals migration; approved retention and backup review required.');
    }
};
