<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\StaffResourceContext;
use App\Models\StaffMember;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
