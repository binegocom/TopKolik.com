<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\StaffResourceContext;
use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffSupportTicketController extends Controller
{
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

    public function reply(Request $request, int $ticket): JsonResponse
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

        [$reply, $replayed] = DB::transaction(function () use ($staff, $token, $ticket, $data): array {
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

            return [$created, false];
        });

        return response()->json([
            'reply' => [
                'id' => $reply->getKey(),
                'ticket_id' => $reply->support_ticket_id,
                'created_at' => $reply->created_at?->toIso8601String(),
            ],
            'replayed' => $replayed,
        ], $replayed ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }

    public function escalate(Request $request, int $ticket): JsonResponse
    {
        $request->validate([
            'assigned_staff_id' => ['missing'],
            'escalated_by_staff_id' => ['missing'],
            'status' => ['missing'],
        ]);

        /** @var StaffMember $staff */
        $staff = $request->user();
        $token = $this->presentedStaffToken($request);

        $record = DB::transaction(function () use ($staff, $token, $ticket): SupportTicket {
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

            $record->forceFill([
                'status' => 'pending',
                'escalated_at' => now(),
                'escalated_by_staff_id' => $staff->getKey(),
            ])->save();

            return $record;
        });

        return response()->json([
            'ticket' => [
                'id' => $record->getKey(),
                'status' => $record->status,
                'escalated_at' => $record->escalated_at?->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function presentedStaffToken(Request $request): ?PersonalAccessToken
    {
        $presented = $request->bearerToken();

        return $presented ? PersonalAccessToken::findToken($presented) : null;
    }
}
