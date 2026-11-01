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

final class StaffFinanceEmergencyDeactivationController extends Controller
{
    /**
     * Emergency offboarding is deliberately one-way. It cannot activate a
     * member, change roles, approve an entitlement or grant any privilege.
     */
    public function deactivate(Request $request, int $staff, SecurityAuditRecorder $audit): JsonResponse
    {
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
            'role' => ['missing'],
            'is_active' => ['missing'],
            'entitlement' => ['missing'],
            'authorization_reference' => ['missing'],
        ]);

        if ($staff === (int) $actor->getKey()) {
            abort(403, 'An administrator cannot deactivate their own staff account.');
        }

        DB::transaction(function () use ($request, $actor, $token, $staff, $audit): void {
            // Preserve the existing finance approval/revocation lock order:
            // acting administrator grant, target staff, then entitlement.
            $adminGrant = DB::table('staff_security_admin_grants')
                ->where('staff_member_id', $actor->getKey())
                ->lockForUpdate()->first();
            if (! $adminGrant || ! $adminGrant->is_active) {
                abort(403);
            }
            Gate::forUser($actor)->authorize('staff-ads-assignments-admin', [$token]);

            $target = StaffMember::query()->whereKey($staff)->lockForUpdate()->first();
            if (! $target || ! $target->is_active || $target->role !== 'finance') {
                abort(404);
            }

            $entitlement = DB::table('staff_finance_entitlements')
                ->where('staff_member_id', $staff)->lockForUpdate()->first();
            if ($entitlement && $entitlement->is_active) {
                DB::table('staff_finance_entitlements')
                    ->where('id', $entitlement->id)->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            // Prevent latent administrator or advertiser privileges from
            // reappearing if a separate operator later reactivates this user.
            DB::table('staff_security_admin_grants')
                ->where('staff_member_id', $staff)->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('staff_advertiser_assignments')
                ->where('staff_member_id', $staff)->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            $target->forceFill(['is_active' => false])->save();
            $target->tokens()->delete();

            // Audit failure rolls back account, grants and token revocation.
            $audit->record(
                $actor, 'staff.finance_access.staff_deactivated',
                'staff_member', $staff, $request->attributes->get('request_id'),
            );
        }, attempts: 3);

        return response()->json(['staff_status' => 'deactivated'])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
