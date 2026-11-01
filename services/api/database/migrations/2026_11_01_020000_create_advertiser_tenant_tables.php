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
        Schema::create('advertiser_organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
        });

        Schema::create('advertiser_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 254)->unique();
            $table->string('password');
            $table->boolean('is_active')->default(false);
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('advertiser_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advertiser_organization_id')->constrained('advertiser_organizations')->restrictOnDelete();
            $table->foreignId('advertiser_account_id')->constrained('advertiser_accounts')->restrictOnDelete();
            $table->string('role', 16);
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
            $table->unique(['advertiser_organization_id', 'advertiser_account_id'], 'advertiser_membership_unique');
            $table->index(['advertiser_account_id', 'is_active'], 'advertiser_member_account_active_idx');
        });

        Schema::create('advertiser_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advertiser_organization_id')->constrained('advertiser_organizations')->restrictOnDelete();
            $table->text('title');
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->index(['advertiser_organization_id', 'status', 'id'], 'advertiser_campaign_tenant_state_idx');
        });

        DB::statement("ALTER TABLE advertiser_memberships ADD CONSTRAINT advertiser_membership_role_check CHECK (role IN ('owner','editor','viewer'))");
        DB::statement("ALTER TABLE advertiser_campaigns ADD CONSTRAINT advertiser_campaign_state_check CHECK (status IN ('draft','in_review'))");
        DB::statement("ALTER TABLE advertiser_campaigns ADD CONSTRAINT advertiser_campaign_revision_check CHECK (revision >= 1)");
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only advertiser migration: approved backup and data review required.');
    }
};
