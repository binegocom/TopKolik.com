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
        'staff.advertiser_assignment.relinquished',
        'staff.security_admin_grant.relinquished',
        'staff.security_admin_grant.revoked',
        'staff.advertiser_assignment.requested',
        'staff.advertiser_assignment.approved',
        'staff.advertiser_assignment.rejected',
        'staff.finance_access.requested',
        'staff.finance_access.approved',
        'staff.finance_access.rejected',
        'staff.finance_access.relinquished',
        'staff.finance_access.revoked',
        'support.ticket.replied',
        'support.ticket.escalated',
        'support.ticket.escalation_reviewed',
        'support.ticket.escalation_reviewer_relinquished',
        'support.ticket.escalation_reviewer_recovered',
        'support.ticket.resolved',
    ];

    private const SUBJECT_TYPES = ['staff_member', 'support_ticket', 'advertiser_organization'];

    public static function allowedActions(): array
    {
        return self::ACTIONS;
    }

    public static function allowedSubjectTypes(): array
    {
        return self::SUBJECT_TYPES;
    }


    /**
     * Persist a failed staff authentication without revealing the attempted
     * identifier, password, MFA factor, IP address or bearer token.
     * Unknown principals are represented without inserting fake staff users.
     */
    public function recordFailure(
        string $action,
        string $identity,
        ?StaffMember $staff = null,
        ?string $requestId = null,
    ): void {
        $normalized = mb_strtolower(trim($identity));
        if (! in_array($action, ['staff.login.failed', 'staff.step_up.failed'], true)
            || $normalized === '' || mb_strlen($normalized) > 254
            || ($staff !== null && ! $staff->exists)
            || ($action === 'staff.step_up.failed' && $staff === null)
            || ($requestId !== null && ! Str::isUuid($requestId))) {
            throw new InvalidArgumentException('Invalid authentication failure audit event.');
        }

        $key = config('app.key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new \LogicException('Authentication audit fingerprint key is not configured.');
        }

        DB::table('security_auth_failures')->insert([
            'staff_member_id' => $staff?->getKey(),
            'action' => $action,
            'identity_fingerprint' => hash_hmac('sha256', 'staff:'. $normalized, $key),
            'request_id' => $requestId,
            'created_at' => now(),
        ]);
    }

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
