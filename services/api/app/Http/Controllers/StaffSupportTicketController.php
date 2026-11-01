<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\StaffResourceContext;
use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffSupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,pending,resolved'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'assigned_staff_id' => ['missing'],
            'staff_member_id' => ['missing'],
            'include_unassigned' => ['missing'],
            'include_all' => ['missing'],
            'with_replies' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->presentedStaffToken($request);
        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Gate::forUser($staff)->authorize('staff-support-tickets-self', [$token]);

        // Apply the authenticated assignment at SQL level, not in memory.
        // Keyset pagination avoids offset drift when tickets are added.
        $limit = (int) ($filters['limit'] ?? 20);
        $query = SupportTicket::query()->where('assigned_staff_id', $staff->getKey());

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['before_id'])) {
            $query->where('id', '<', (int) $filters['before_id']);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)
            ->get(['id', 'subject', 'status', 'created_at', 'updated_at', 'escalated_at']);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->map(static fn (SupportTicket $ticket): array => [
            'id' => (int) $ticket->getKey(),
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'escalated_at' => $ticket->escalated_at?->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'tickets' => $page,
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

        // Scope the SQL read before constructing authorization context.
        // An unassigned or another operator's ticket is indistinguishable
        // from a nonexistent ticket, including for staff with valid MFA.
        $record = SupportTicket::query()
            ->whereKey($ticket)
            ->where('assigned_staff_id', $staff->getKey())
            ->firstOrFail();

        // Never trust a potentially cached guard token for authorization.
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Gate::forUser($staff)->authorize('staff-resource', [
            'support.ticket.view',
            new StaffResourceContext(
                id: (int) $record->getKey(),
                kind: 'support_ticket',
                state: $record->status,
                assignedStaffId: (int) $record->assigned_staff_id,
            ),
            $token,
        ]);

        return response()->json([
            'ticket' => [
                'id' => $record->getKey(),
                'subject' => $record->subject,
                'message' => $record->message,
                'status' => $record->status,
                'created_at' => $record->created_at?->toIso8601String(),
                'updated_at' => $record->updated_at?->toIso8601String(),
                'escalated_at' => $record->escalated_at?->toIso8601String(),
                'replies' => $record->replies()->orderBy('id')->get()
                    ->map(static fn (SupportTicketReply $reply): array => [
                        'id' => $reply->getKey(),
                        'message' => $reply->message,
                        'created_at' => $reply->created_at?->toIso8601String(),
                    ])->all(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function reply(Request $request, int $ticket, SecurityAuditRecorder $audit): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'idempotency_key' => ['required', 'uuid'],
            'support_ticket_id' => ['missing'],
            'staff_member_id' => ['missing'],
            'status' => ['missing'],
            'assigned_staff_id' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->presentedStaffToken($request);

        [$reply, $replayed] = DB::transaction(function () use ($staff, $token, $ticket, $data, $audit, $request): array {
            // Lock the actual scoped record; authorization must be evaluated
            // against the persisted assignment and current state.
            $record = SupportTicket::query()
                ->whereKey($ticket)
                ->where('assigned_staff_id', $staff->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($staff)->authorize('staff-resource', [
                'support.ticket.reply',
                new StaffResourceContext(
                    id: (int) $record->getKey(),
                    kind: 'support_ticket',
                    state: $record->status,
                    assignedStaffId: (int) $record->assigned_staff_id,
                ),
                $token,
            ]);

            $fingerprint = hash('sha256', $data['message']);
            $existing = SupportTicketReply::query()
                ->where('support_ticket_id', $record->getKey())
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->message_fingerprint, $fingerprint)
                    || (int) $existing->staff_member_id !== (int) $staff->getKey()) {
                    abort(409, 'Idempotency key was already used.');
                }

                return [$existing, true];
            }

            $created = SupportTicketReply::query()->create([
                'support_ticket_id' => $record->getKey(),
                'staff_member_id' => $staff->getKey(),
                'idempotency_key' => $data['idempotency_key'],
                'message_fingerprint' => $fingerprint,
                'message' => $data['message'],
            ]);

            if ($record->status === 'open') {
                $record->forceFill(['status' => 'pending'])->save();
            }

            $audit->record($staff, 'support.ticket.replied', 'support_ticket', (int) $record->getKey(), $request->attributes->get('request_id'));

            return [$created, false];
        }, 3); // Retry deadlocks with a fresh scoped row lock and policy check.

        return response()->json([
            'reply' => [
                'id' => $reply->getKey(),
                'ticket_id' => $reply->support_ticket_id,
                'created_at' => $reply->created_at?->toIso8601String(),
            ],
            'replayed' => $replayed,
        ], $replayed ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }

    public function escalate(Request $request, int $ticket, SecurityAuditRecorder $audit): JsonResponse
    {
        $request->validate([
            'assigned_staff_id' => ['missing'],
            'escalated_by_staff_id' => ['missing'],
            'status' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->presentedStaffToken($request);

        $record = DB::transaction(function () use ($staff, $token, $ticket, $audit, $request): SupportTicket {
            $record = SupportTicket::query()
                ->whereKey($ticket)
                ->where('assigned_staff_id', $staff->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($staff)->authorize('staff-resource', [
                'support.ticket.escalate',
                new StaffResourceContext(
                    id: (int) $record->getKey(),
                    kind: 'support_ticket',
                    state: $record->status,
                    assignedStaffId: (int) $record->assigned_staff_id,
                ),
                $token,
            ]);

            if ($record->escalated_at !== null) {
                abort(409, 'Ticket is already escalated.');
            }

            // Assign a distinct, currently eligible reviewer server-side.
            // If no reviewer exists, keep the escalation on hold.
            $reviewer = StaffMember::query()
                ->where('role', 'support')
                ->where('is_active', true)
                ->whereNotNull('email_verified_at')
                ->whereNotNull('mfa_enrolled_at')
                ->where('id', '<>', $staff->getKey())
                ->orderBy('id')
                ->first(['id']);

            $record->forceFill([
                'status' => 'pending',
                'escalation_reviewer_staff_id' => $reviewer?->getKey(),
                'escalated_at' => now(),
                'escalated_by_staff_id' => $staff->getKey(),
            ])->save();

            $audit->record($staff, 'support.ticket.escalated', 'support_ticket', (int) $record->getKey(), $request->attributes->get('request_id'));

            return $record;
        }, 3); // Retry deadlocks; authorization and escalation state are rechecked.

        return response()->json([
            'ticket' => [
                'id' => $record->getKey(),
                'status' => $record->status,
                'escalated_at' => $record->escalated_at?->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Finalize an answered ticket only after a fresh staff MFA step-up.
     * Escalated cases are deliberately held for a separate review workflow.
     */
    public function resolve(Request $request, int $ticket, SecurityAuditRecorder $audit): JsonResponse
    {
        $data = $request->validate([
            'resolution_note' => ['required', 'string', 'min:1', 'max:2000'],
            'status' => ['missing'],
            'assigned_staff_id' => ['missing'],
            'resolved_at' => ['missing'],
            'resolved_by_staff_id' => ['missing'],
            'escalated_at' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->presentedStaffToken($request);

        $record = DB::transaction(function () use ($staff, $token, $ticket, $data, $audit, $request): SupportTicket {
            $record = SupportTicket::query()
                ->whereKey($ticket)
                ->where('assigned_staff_id', $staff->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($staff)->authorize('staff-resource', [
                'support.ticket.resolve',
                new StaffResourceContext(
                    id: (int) $record->getKey(),
                    kind: 'support_ticket',
                    state: $record->status,
                    assignedStaffId: (int) $record->assigned_staff_id,
                ),
                $token,
            ]);

            if ($record->escalated_at !== null && $record->escalation_reviewed_at === null) {
                abort(409, 'Escalated tickets require separate review.');
            }

            $record->forceFill([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by_staff_id' => $staff->getKey(),
                'resolution_note' => $data['resolution_note'],
            ])->save();

            $audit->record(
                $staff,
                'support.ticket.resolved',
                'support_ticket',
                (int) $record->getKey(),
                $request->attributes->get('request_id'),
            );

            return $record;
        }, 3); // Retry only concurrency failures; each attempt re-locks and re-authorizes.

        return response()->json([
            'ticket' => [
                'id' => $record->getKey(),
                'status' => $record->status,
                'resolved_at' => $record->resolved_at?->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function presentedStaffToken(Request $request): ?PersonalAccessToken
    {
        $presented = $request->bearerToken();

        return $presented ? PersonalAccessToken::findToken($presented) : null;
    }
}
