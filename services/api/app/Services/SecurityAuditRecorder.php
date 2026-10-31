<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StaffMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SecurityAuditRecorder
{
    private const ACTIONS = [
        'staff.invitation.accepted',
        'staff.mfa.enrolled',
        'staff.activated',
        'staff.session.started',
        'staff.session.stepped_up',
        'staff.session.ended',
        'support.ticket.replied',
        'support.ticket.escalated',
    ];

    private const SUBJECT_TYPES = ['staff_member', 'support_ticket'];

    public function record(
        StaffMember $actor,
        string $action,
        string $subjectType,
        int $subjectId,
        ?string $requestId = null,
    ): void {
        if (! $actor->exists || ! in_array($action, self::ACTIONS, true)
            || ! in_array($subjectType, self::SUBJECT_TYPES, true)
            || $subjectId < 1 || ($requestId !== null && ! Str::isUuid($requestId))) {
            throw new InvalidArgumentException('Invalid security audit event.');
        }

        // Explicitly allowlisted metadata only: never store request bodies,
        // credentials, MFA factors, tokens, ticket messages or email addresses.
        // Call from the same DB transaction as the privileged state change.
        DB::table('security_audit_events')->insert([
            'staff_member_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);
    }
}
