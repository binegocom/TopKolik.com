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

final class StaffFinanceEntitlementController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        [$staff, $token] = $this->session($request);
        Gate::forUser($staff)->authorize('staff-finance-entitlement', [$token]);

        return $this->privateResponse(['access' => 'active']);
    }

    public function relinquish(Request $request, SecurityAuditRecorder $audit): JsonResponse
    {
        [$staff, $token] = $this->session($request);
        Gate::forUser($staff)->authorize('staff-finance-entitlement', [$token]);
        $request->validate([
            'staff_member_id' => ['missing'],
            'is_active' => ['missing'],
            'role' => ['missing'],
            'approved_by' => ['missing'],
        ]);

        DB::transaction(function () use ($request, $staff, $token, $audit): void {
            $grant = DB::table('staff_finance_entitlements')
                ->where('staff_member_id', $staff->getKey())
                ->lockForUpdate()->first();
            if (! $grant || ! $grant->is_active) {
                abort(403);
            }
            Gate::forUser($staff)->authorize('staff-finance-entitlement', [$token]);

            $updated = DB::table('staff_finance_entitlements')
                ->where('id', $grant->id)->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);
            if ($updated !== 1) {
                abort(409, 'Finance entitlement was already relinquished.');
            }

            $audit->record(
                $staff, 'staff.finance_access.relinquished',
                'staff_member', (int) $staff->getKey(),
                $request->attributes->get('request_id'),
            );
        }, 3);

        return $this->privateResponse(['access' => 'relinquished']);
    }

    /** @return array{StaffMember, PersonalAccessToken} */
    private function session(Request $request): array
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
        return [$staff, $token];
    }

    private function privateResponse(array $payload): JsonResponse
    {
        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
