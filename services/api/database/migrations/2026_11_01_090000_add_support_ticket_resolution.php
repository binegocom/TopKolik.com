<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only support resolution migration: reviewed remediation required.');
    }
};
