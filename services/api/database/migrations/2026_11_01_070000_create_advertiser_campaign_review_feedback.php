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
        Schema::create('advertiser_campaign_review_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advertiser_organization_id')->constrained('advertiser_organizations')->restrictOnDelete();
            $table->foreignId('advertiser_campaign_id')->constrained('advertiser_campaigns')->restrictOnDelete();
            $table->foreignId('staff_member_id')->constrained('staff_members')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('feedback');
            $table->unsignedBigInteger('from_revision');
            $table->unsignedBigInteger('to_revision');
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['advertiser_campaign_id', 'to_revision'], 'advertiser_feedback_campaign_revision_unique');
            $table->index(['advertiser_organization_id', 'advertiser_campaign_id', 'id'], 'advertiser_feedback_tenant_idx');
        });

        DB::statement("ALTER TABLE advertiser_campaign_review_feedback
            ADD CONSTRAINT advertiser_feedback_action_check CHECK (action = 'changes_requested')");
        DB::statement("ALTER TABLE advertiser_campaign_review_feedback
            ADD CONSTRAINT advertiser_feedback_revision_check CHECK (from_revision >= 1 AND to_revision = from_revision + 1)");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_advertiser_feedback_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'advertiser review feedback is append-only';
END;
$$;
CREATE TRIGGER advertiser_feedback_no_changes
BEFORE UPDATE OR DELETE ON advertiser_campaign_review_feedback
FOR EACH ROW EXECUTE FUNCTION reject_advertiser_feedback_mutation();
CREATE TRIGGER advertiser_feedback_no_truncate
BEFORE TRUNCATE ON advertiser_campaign_review_feedback
FOR EACH STATEMENT EXECUTE FUNCTION reject_advertiser_feedback_mutation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only review feedback migration: retention and backup approval required.');
    }
};
