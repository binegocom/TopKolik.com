<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->text('mfa_secret')->nullable();
            $table->text('mfa_pending_secret')->nullable();
            $table->unsignedBigInteger('mfa_last_step')->nullable();
            $table->char('mfa_setup_token_hash', 64)->nullable()->unique();
            $table->timestampTz('mfa_setup_expires_at')->nullable();
            $table->string('activated_by', 120)->nullable();
            $table->timestampTz('activated_at')->nullable();
        });

        Schema::create('staff_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_member_id')->constrained('staff_members')->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->timestampTz('created_at');
            $table->unique(['staff_member_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only MFA migration: do not destroy enrolled staff secrets or recovery records.');
    }
};
