<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\SecurityAuditRecorder;
use App\Services\SupportEscalationReviewerSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffSupportEscalationController extends Controller
{
    /**
     * Reviewer-only work queue. The authenticated principal is the sole
     * assignment selector; no ticket content or staff identifiers are listed.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'staff_member_id' => ['missing'],
            'escalation_reviewer_staff_id' => ['missing'],
            'assigned_staff_id' => ['missing'],
            'include_all' => ['missing'],
            'with_replies' => ['missing'],
            'status' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->presentedToken($request);
        Gate::forUser($staff)->authorize('staff-support-escalations-self', [$token]);

        $limit = (int) ($filters['limit'] ?? 20);
        $query = SupportTicket::query()
            ->where('escalation_reviewer_staff_id', $staff->getKey())
            ->where('status', 'pending')
            ->whereNotNull('escalated_at')
            ->whereNull('escalation_reviewed_at')
            ->whereNull('escalation_reviewed_by_staff_id');

        if (isset($filters['before_id'])) {
            $query->where('id', '<', (int) $filters['before_id']);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)
            ->get(['id', 'escalated_at']);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->map(static fn (SupportTicket $ticket): array => [
            'id' => (int) $ticket->getKey(),
            'escalated_at' => $ticket->escalated_at?->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'escalations' => $page,
            'next_before_id' => $hasMore ? $page[count($page) - 1]['id'] : null,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        /** @var StaffMember $staff */
        $staff = $request->user();
        $record = SupportTicket::query()
            ->whereKey($ticket)
            ->where('escalation_reviewer_staff_id', $staff->getKey())
            ->firstOrFail();

        Gate::forUser($staff)->authorize('staff-support-escalation-review', [
            $record, $this->presentedToken($request),
        ]);

        return response()->json([
            'ticket' => [
                'id' => $record->getKey(),
                'subject' => $record->subject,
                'message' => $record->message,
                'status' => $record->status,
                'escalated_at' => $record->escalated_at?->toIso8601String(),
                'replies' => $record->replies()->orderBy('id')->get()
                    ->map(static fn (SupportTicketReply $reply): array => [
                        'id' => $reply->getKey(),
                        'message' => $reply->message,
                        'created_at' => $reply->created_at?->toIso8601String(),
                    ])->all(),
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function approve(Request $request, int $ticket, SecurityAuditRecorder $audit): JsonResponse
    {
        $data = $request->validate([
            'review_note' => ['required', 'string', 'min:1', 'max:2000'],
            'status' => ['missing'],
            'assigned_staff_id' => ['missing'],
            'escalation_reviewer_staff_id' => ['missing'],
            'escalation_reviewed_at' => ['missing'],
            'escalation_reviewed_by_staff_id' => ['missing'],
            'resolution_note' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->presentedToken($request);

        $record = DB::transaction(function () use ($staff, $token, $ticket, $data, $audit, $request): SupportTicket {
            $record = SupportTicket::query()
                ->whereKey($ticket)
                ->where('escalation_reviewer_staff_id', $staff->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($staff)->authorize('staff-support-escalation-review', [$record, $token]);

            $record->forceFill([
                'escalation_reviewed_at' => now(),
                'escalation_reviewed_by_staff_id' => $staff->getKey(),
                'escalation_review_note' => $data['review_note'],
            ])->save();

            $audit->record(
                $staff,
                'support.ticket.escalation_reviewed',
                'support_ticket',
                (int) $record->getKey(),
                $request->attributes->get('request_id'),
            );

            return $record;
        }, 3);

        return response()->json([
            'ticket' => [
                'id' => $record->getKey(),
                'status' => $record->status,
                'escalation_reviewed_at' => $record->escalation_reviewed_at?->toIso8601String(),
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }


    /**
     * Allow the currently assigned reviewer to step aside without giving
     * clients the ability to select a replacement or bypass independent review.
     * With no eligible replacement the ticket stays held for operations.
     */
    public function relinquish(Request $request, int $ticket, SecurityAuditRecorder $audit): JsonResponse
    {
        $request->validate([
            'escalation_reviewer_staff_id' => ['missing'],
            'assigned_staff_id' => ['missing'],
            'escalation_reviewed_at' => ['missing'],
            'escalation_reviewed_by_staff_id' => ['missing'],
            'status' => ['missing'],
            'review_note' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->presentedToken($request);

        $replacementAssigned = DB::transaction(function () use ($staff, $token, $ticket, $audit, $request): bool {
            $record = SupportTicket::query()
                ->whereKey($ticket)
                ->where('escalation_reviewer_staff_id', $staff->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($staff)->authorize('staff-support-escalation-review', [$record, $token]);

            // Select the least-loaded eligible independent reviewer.
            // A transaction advisory lock also coordinates initial escalation
            // and security-admin recovery assignments.
            $replacement = app(SupportEscalationReviewerSelector::class)->select([
                $staff->getKey(),
                $record->assigned_staff_id,
                $record->escalated_by_staff_id,
            ]);

            $record->forceFill([
                'escalation_reviewer_staff_id' => $replacement?->getKey(),
            ])->save();

            // The assignment and audit record must commit or roll back together.
            $audit->record(
                $staff,
                'support.ticket.escalation_reviewer_relinquished',
                'support_ticket',
                (int) $record->getKey(),
                $request->attributes->get('request_id'),
            );

            return $replacement !== null;
        }, 3);

        return response()->json([
            'ticket' => [
                'id' => $ticket,
                'status' => 'pending',
                'reviewer_assigned' => $replacementAssigned,
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    private function presentedToken(Request $request): ?PersonalAccessToken
    {
        $bearer = $request->bearerToken();

        return $bearer ? PersonalAccessToken::findToken($bearer) : null;
    }
}
