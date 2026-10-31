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
        Schema::create('staff_members', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 254)->unique();
            $table->string('password');
            $table->string('role', 32);
            $table->timestampTz('email_verified_at');
            $table->boolean('is_active')->default(false);
            $table->timestampTz('mfa_enrolled_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 254)->index();
            $table->string('role', 32);
            $table->char('token_hash', 64);
            $table->string('issued_by', 120);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
        });

        // Defense in depth: client/request input cannot introduce unapproved roles.
        DB::statement("ALTER TABLE staff_members ADD CONSTRAINT staff_member_role_check CHECK (role IN ('editor','support','ads','finance'))");
        DB::statement("ALTER TABLE staff_invitations ADD CONSTRAINT staff_invite_role_check CHECK (role IN ('editor','support','ads','finance'))");
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only staff enrollment migration: reviewed remediation required.');
    }
};
