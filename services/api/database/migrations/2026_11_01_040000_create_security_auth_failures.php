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
        Schema::create('security_auth_failures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_member_id')->nullable()->constrained('staff_members')->restrictOnDelete();
            $table->string('action', 40);
            $table->char('identity_fingerprint', 64);
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['staff_member_id', 'id'], 'auth_failure_staff_idx');
            $table->index(['identity_fingerprint', 'id'], 'auth_failure_identity_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE security_auth_failures
ADD CONSTRAINT auth_failure_action_check
CHECK (action IN ('staff.login.failed', 'staff.step_up.failed'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE security_auth_failures
ADD CONSTRAINT auth_failure_step_up_principal_check
CHECK (action <> 'staff.step_up.failed' OR staff_member_id IS NOT NULL)
SQL);

        // A dedicated table supports unknown identities without creating
        // fake staff rows or exposing attempted emails in the staff audit ledger.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_security_auth_failure_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'security authentication failures are append-only';
END;
$$;
CREATE TRIGGER security_auth_failure_reject_change
BEFORE UPDATE OR DELETE ON security_auth_failures
FOR EACH ROW EXECUTE FUNCTION reject_security_auth_failure_mutation();
CREATE TRIGGER security_auth_failure_reject_truncate
BEFORE TRUNCATE ON security_auth_failures
FOR EACH STATEMENT EXECUTE FUNCTION reject_security_auth_failure_mutation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only authentication failure ledger: approved retention and backup review required.');
    }
};
