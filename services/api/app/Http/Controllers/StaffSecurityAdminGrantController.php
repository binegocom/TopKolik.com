<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffSecurityAdminGrantController extends Controller
{
    public function relinquish(Request $request, SecurityAuditRecorder $audit): JsonResponse
    {
        $staff = $request->user();
        $raw = $request->bearerToken();
        $token = $raw ? PersonalAccessToken::findToken($raw) : null;

        if (! $staff instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || $token->tokenable_type !== $staff->getMorphClass()
            || (string) $token->tokenable_id !== (string) $staff->getKey()) {
            abort(403);
        }

        Gate::forUser($staff)->authorize('staff-ads-assignments-admin', [$token]);

        $request->validate([
            'staff_member_id' => ['missing'],
            'target_staff_member_id' => ['missing'],
            'authorization_reference' => ['missing'],
            'is_active' => ['missing'],
            'role' => ['missing'],
        ]);

        DB::transaction(function () use ($request, $staff, $token, $audit): void {
            // Lock the current actor's grant so a concurrent assignment
            // approval must either precede this revocation or fail closed.
            $grant = DB::table('staff_security_admin_grants')
                ->where('staff_member_id', $staff->getKey())
                ->lockForUpdate()
                ->first();

            if (! $grant || ! $grant->is_active) {
                abort(403);
            }

            // Recheck the persisted principal and grant under the lock.
            Gate::forUser($staff)->authorize('staff-ads-assignments-admin', [$token]);

            $changed = DB::table('staff_security_admin_grants')
                ->where('id', $grant->id)
                ->where('staff_member_id', $staff->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            if ($changed !== 1) {
                abort(409, 'Security administrator grant was already relinquished.');
            }

            $audit->record(
                $staff,
                'staff.security_admin_grant.relinquished',
                'staff_member',
                (int) $staff->getKey(),
                $request->attributes->get('request_id'),
            );
        }, attempts: 3);

        // There is deliberately no self-grant or reactivation endpoint.
        return response()->json([
            'grant_status' => 'relinquished',
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
