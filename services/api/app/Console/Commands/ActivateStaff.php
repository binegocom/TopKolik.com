<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ActivateStaff extends Command
{
    protected $signature = 'staff:activate {email : Staff email} {--operator= : Authorized operator ID}';

    protected $description = 'Manually approve an enrolled, verified staff identity; requires privileged server access';

    public function handle(SecurityAuditRecorder $audit): int
    {
        $operator = trim((string) $this->option('operator'));
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if ($operator === '' || strlen($operator) > 120 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Valid email and operator identifier required.');

            return self::FAILURE;
        }

        $approved = DB::transaction(static function () use ($email, $operator, $audit): bool {
            $staff = StaffMember::query()->where('email', $email)->lockForUpdate()->first();
            if (! $staff || ! $staff->email_verified_at || ! $staff->mfa_enrolled_at || ! $staff->mfa_secret) {
                return false;
            }

            $staff->forceFill([
                'is_active' => true,
                'activated_by' => $operator,
                'activated_at' => now(),
            ])->save();
            $audit->record($staff, 'staff.activated', 'staff_member', (int) $staff->getKey());

            return true;
        });

        if (! $approved) {
            $this->error('Staff identity is missing or has not completed mandatory MFA.');

            return self::FAILURE;
        }

        $this->info('Staff identity approved. The operator must configure RBAC separately.');

        return self::SUCCESS;
    }
}
