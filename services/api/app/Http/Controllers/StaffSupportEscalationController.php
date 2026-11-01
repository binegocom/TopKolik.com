<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffSupportEscalationController extends Controller
{
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

    private function presentedToken(Request $request): ?PersonalAccessToken
    {
        $bearer = $request->bearerToken();

        return $bearer ? PersonalAccessToken::findToken($bearer) : null;
    }
}
