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
    /**
     * An independently authorized security administrator may revoke another
     * administrator's already-active grant, never create or reactivate one.
     * The target's staff account, role and ordinary tokens are unchanged.
     */
    public function revokeOther(
        Request $request,
        int $staff,
        SecurityAuditRecorder $audit,
    ): JsonResponse {
        $actor = $request->user();
        $raw = $request->bearerToken();
        $token = $raw ? PersonalAccessToken::findToken($raw) : null;

        if (! $actor instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || $token->tokenable_type !== $actor->getMorphClass()
            || (string) $token->tokenable_id !== (string) $actor->getKey()) {
            abort(403);
        }

        Gate::forUser($actor)->authorize('staff-ads-assignments-admin', [$token]);

        $request->validate([
            'staff_member_id' => ['missing'],
            'target_staff_member_id' => ['missing'],
            'authorization_reference' => ['missing'],
            'is_active' => ['missing'],
            'role' => ['missing'],
            'grant' => ['missing'],
        ]);

        // Self-relinquishment has a separate explicit route. A compromised
        // staff client cannot substitute the authenticated actor in this flow.
        if ($staff === (int) $actor->getKey()) {
            abort(403);
        }

        DB::transaction(function () use ($request, $actor, $token, $staff, $audit): void {
            // Serialize revocation with pending approvals and voluntary
            // relinquishment. Always lock actor grant before target grant.
            $actorGrant = DB::table('staff_security_admin_grants')
                ->where('staff_member_id', $actor->getKey())
                ->lockForUpdate()
                ->first();
            if (! $actorGrant || ! $actorGrant->is_active) {
                abort(403);
            }

            Gate::forUser($actor)->authorize('staff-ads-assignments-admin', [$token]);

            $targetGrant = DB::table('staff_security_admin_grants')
                ->where('staff_member_id', $staff)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (! $targetGrant) {
                abort(404);
            }

            $changed = DB::table('staff_security_admin_grants')
                ->where('id', $targetGrant->id)
                ->where('staff_member_id', $staff)
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            if ($changed !== 1) {
                abort(409, 'Security administrator grant revocation conflict.');
            }

            // Both statements must succeed or roll back together.
            $audit->record(
                $actor,
                'staff.security_admin_grant.revoked',
                'staff_member',
                $staff,
                $request->attributes->get('request_id'),
            );
        }, attempts: 3);

        return response()->json([
            'grant_status' => 'revoked',
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

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
