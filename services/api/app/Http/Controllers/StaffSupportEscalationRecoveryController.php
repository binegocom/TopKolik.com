<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Services\SecurityAuditRecorder;
use App\Services\SupportEscalationReviewerSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffSupportEscalationRecoveryController extends Controller
{
    /**
     * Recover only pending, escalated and unreviewed cases without an
     * assigned reviewer. The requesting admin cannot choose or become the
     * replacement reviewer, and no ticket content is returned.
     */
    public function recoverReviewer(
        Request $request,
        int $ticket,
        SecurityAuditRecorder $audit,
    ): JsonResponse {
        $actor = $request->user();
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;

        if (! $actor instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || $token->tokenable_type !== $actor->getMorphClass()
            || (string) $token->tokenable_id !== (string) $actor->getKey()) {
            abort(403);
        }

        Gate::forUser($actor)->authorize('staff-support-escalation-recovery', [$token]);

        $request->validate([
            'escalation_reviewer_staff_id' => ['missing'],
            'assigned_staff_id' => ['missing'],
            'escalated_by_staff_id' => ['missing'],
            'escalation_reviewed_by_staff_id' => ['missing'],
            'escalation_reviewed_at' => ['missing'],
            'review_note' => ['missing'],
            'status' => ['missing'],
            'staff_member_id' => ['missing'],
        ]);

        DB::transaction(function () use ($request, $actor, $token, $ticket, $audit): void {
            // Shared grant lock with emergency revocation and staff access
            // administration: a revoked operator cannot reuse stale access.
            $grant = DB::table('staff_security_admin_grants')
                ->where('staff_member_id', $actor->getKey())
                ->lockForUpdate()
                ->first();
            if (! $grant || ! $grant->is_active) {
                abort(403);
            }

            Gate::forUser($actor)->authorize('staff-support-escalation-recovery', [$token]);

            $record = SupportTicket::query()
                ->whereKey($ticket)
                ->where('status', 'pending')
                ->whereNotNull('escalated_at')
                ->whereNull('escalation_reviewed_at')
                ->whereNull('escalation_reviewed_by_staff_id')
                ->whereNull('escalation_reviewer_staff_id')
                ->lockForUpdate()
                ->firstOrFail();

            // The server chooses the least-loaded eligible independent
            // reviewer, excluding the administrator, assignee and escalator.
            $reviewer = app(SupportEscalationReviewerSelector::class)->select([
                $actor->getKey(),
                $record->assigned_staff_id,
                $record->escalated_by_staff_id,
            ]);

            if (! $reviewer) {
                abort(409, 'No eligible independent escalation reviewer is available.');
            }

            $record->forceFill([
                'escalation_reviewer_staff_id' => $reviewer->getKey(),
            ])->save();

            // Persist the assignment and metadata-only audit together.
            $audit->record(
                $actor,
                'support.ticket.escalation_reviewer_recovered',
                'support_ticket',
                (int) $record->getKey(),
                $request->attributes->get('request_id'),
            );
        }, 3);

        return response()->json([
            'ticket' => [
                'id' => $ticket,
                'status' => 'pending',
                'reviewer_assigned' => true,
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
