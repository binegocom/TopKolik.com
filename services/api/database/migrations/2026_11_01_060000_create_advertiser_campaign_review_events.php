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
        Schema::create('advertiser_campaign_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advertiser_organization_id')
                ->constrained('advertiser_organizations')->restrictOnDelete();
            $table->foreignId('advertiser_campaign_id')
                ->constrained('advertiser_campaigns')->restrictOnDelete();
            $table->foreignId('advertiser_account_id')
                ->constrained('advertiser_accounts')->restrictOnDelete();
            $table->string('action', 40);
            $table->unsignedBigInteger('from_revision');
            $table->unsignedBigInteger('to_revision');
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['advertiser_campaign_id', 'to_revision'],
                'campaign_review_event_revision_unique',
            );
            $table->index(
                ['advertiser_organization_id', 'id'],
                'campaign_review_event_org_idx',
            );
        });

        DB::statement("ALTER TABLE advertiser_campaign_review_events
            ADD CONSTRAINT campaign_review_event_action_check
            CHECK (action = 'submitted_for_review')");
        DB::statement("ALTER TABLE advertiser_campaign_review_events
            ADD CONSTRAINT campaign_review_event_revision_check
            CHECK (from_revision >= 1 AND to_revision = from_revision + 1)");

        // Insert-only application ledger. Database owner and superuser
        // tampering still requires independent offsite controls.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_campaign_review_event_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'campaign review events are append-only';
END;
$$;
CREATE TRIGGER campaign_review_event_reject_change
BEFORE UPDATE OR DELETE ON advertiser_campaign_review_events
FOR EACH ROW EXECUTE FUNCTION reject_campaign_review_event_mutation();
CREATE TRIGGER campaign_review_event_reject_truncate
BEFORE TRUNCATE ON advertiser_campaign_review_events
FOR EACH STATEMENT EXECUTE FUNCTION reject_campaign_review_event_mutation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only campaign review audit migration: approved data and backup review required.');
    }
};
