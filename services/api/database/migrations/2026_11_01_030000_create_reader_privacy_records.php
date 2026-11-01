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
        Schema::create('reader_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('consent_key', 40);
            $table->boolean('granted');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'id'], 'reader_consent_user_sequence_idx');
        });
        DB::statement("ALTER TABLE reader_consent_events ADD CONSTRAINT reader_consent_key_check CHECK (consent_key IN ('analytics_consent','personalized_news_enabled'))");

        Schema::create('reader_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('pending_legal_review');
            $table->timestampTz('requested_at')->useCurrent();
        });
        DB::statement("ALTER TABLE reader_deletion_requests ADD CONSTRAINT reader_deletion_status_check CHECK (status = 'pending_legal_review')");

        // Independent legal review is required before defining any deletion or
        // retention transition. Neither HTTP code nor a migration can erase data.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_reader_privacy_event_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'reader privacy events are append-only';
END;
$$;
CREATE TRIGGER reader_consent_reject_change
BEFORE UPDATE OR DELETE ON reader_consent_events
FOR EACH ROW EXECUTE FUNCTION reject_reader_privacy_event_mutation();
CREATE TRIGGER reader_consent_reject_truncate
BEFORE TRUNCATE ON reader_consent_events
FOR EACH STATEMENT EXECUTE FUNCTION reject_reader_privacy_event_mutation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only reader privacy migration: reviewed retention and backup required.');
    }
};
