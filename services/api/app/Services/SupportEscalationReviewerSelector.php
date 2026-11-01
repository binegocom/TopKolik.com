<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StaffMember;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Choose an independent reviewer with the fewest pending, unreviewed cases.
 *
 * Call only inside the ticket mutation's PostgreSQL transaction. The shared
 * transaction-scoped advisory lock serializes assignments across escalation,
 * reviewer relinquishment and security-admin recovery. This prevents two
 * concurrent requests from both reading the same pre-assignment workload.
 */
final class SupportEscalationReviewerSelector
{
    public function select(array $excludedStaffIds): ?StaffMember
    {
        // A fixed application namespace; no user-controlled lock keys.
        DB::select('SELECT pg_advisory_xact_lock(207438, 1)');

        $excluded = array_values(array_unique(array_map(
            'intval',
            array_filter($excludedStaffIds, static fn ($id): bool => $id !== null),
        )));

        return StaffMember::query()
            ->select('staff_members.id')
            ->selectSub(
                static fn (Builder $query): Builder => $query
                    ->from('support_tickets')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('support_tickets.escalation_reviewer_staff_id', 'staff_members.id')
                    ->where('support_tickets.status', 'pending')
                    ->whereNotNull('support_tickets.escalated_at')
                    ->whereNull('support_tickets.escalation_reviewed_at')
                    ->whereNull('support_tickets.escalation_reviewed_by_staff_id'),
                'pending_review_count',
            )
            ->where('staff_members.role', 'support')
            ->where('staff_members.is_active', true)
            ->whereNotNull('staff_members.email_verified_at')
            ->whereNotNull('staff_members.mfa_enrolled_at')
            ->when($excluded !== [], static fn ($query) => $query->whereNotIn('staff_members.id', $excluded))
            ->orderBy('pending_review_count')
            ->orderBy('staff_members.id')
            ->lockForUpdate()
            ->first();
    }
}
