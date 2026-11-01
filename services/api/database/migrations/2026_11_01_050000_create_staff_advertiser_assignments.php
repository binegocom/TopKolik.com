<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_advertiser_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_member_id')->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('advertiser_organization_id')->constrained('advertiser_organizations')->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
            $table->unique(['staff_member_id', 'advertiser_organization_id'], 'staff_advertiser_unique');
            $table->index(['advertiser_organization_id', 'is_active'], 'staff_advertiser_org_active_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only staff advertiser assignments: reviewed data remediation required.');
    }
};
