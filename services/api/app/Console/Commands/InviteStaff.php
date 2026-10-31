<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StaffInvitationService;
use Illuminate\Console\Command;
use Throwable;

final class InviteStaff extends Command
{
    protected $signature = 'staff:invite
        {email : Verified recipient email}
        {--role= : editor, support, ads, finance}
        {--operator= : Internal operator identifier for audit trail}';

    protected $description = 'Issue a single-use staff invitation using privileged server CLI access';

    public function handle(StaffInvitationService $service): int
    {
        try {
            $invite = $service->issue(
                (string) $this->argument('email'),
                (string) $this->option('role'),
                (string) $this->option('operator'),
            );
        } catch (Throwable $e) {
            // Avoid echoing transport exceptions that could contain credentials.
            $this->error('Staff invitation failed. Check operator settings and mail service logs.');

            return self::FAILURE;
        }

        $this->info('Invitation sent; ID: '.$invite->id);
        $this->info('Account remains unable to authenticate until staff MFA is configured.');

        return self::SUCCESS;
    }
}
