<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffFinanceAccessInventoryController extends Controller
{
    /**
     * Review the existing active entitlement records without revealing
     * staff contact details or any financial transactions.
     */
    public function active(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'staff_member_id' => ['missing'],
            'target_staff_member_id' => ['missing'],
            'requested_by_staff_member_id' => ['missing'],
            'reviewed_by_staff_member_id' => ['missing'],
            'request_id' => ['missing'],
            'role' => ['missing'],
            'status' => ['missing'],
            'is_active' => ['missing'],
            'include_inactive' => ['missing'],
            'sort' => ['missing'],
        ]);

        $limit = (int) ($filters['limit'] ?? 20);
        $query = DB::table('staff_finance_entitlements as e')
            ->join('staff_members as s', 's.id', '=', 'e.staff_member_id')
            ->where('e.is_active', true);

        if (isset($filters['before_id'])) {
            $query->where('e.id', '<', (int) $filters['before_id']);
        }

        // An active entitlement may be temporarily unusable if the staff
        // member was demoted, disabled or lost MFA/email verification.
        // Surface the discrepancy without exposing identity or factors.
        $rows = $query->orderByDesc('e.id')->limit($limit + 1)->get([
            'e.id', 'e.staff_member_id', 'e.last_approved_by_staff_id', 'e.updated_at',
            DB::raw("CASE WHEN s.is_active = true AND s.role = 'finance' ".
                'AND s.email_verified_at IS NOT NULL '.
                'AND s.mfa_enrolled_at IS NOT NULL THEN 1 ELSE 0 END AS staff_eligible'),
        ]);

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'staff_member_id' => (int) $row->staff_member_id,
            'last_approved_by_staff_id' => $row->last_approved_by_staff_id === null
                ? null : (int) $row->last_approved_by_staff_id,
            'staff_eligible' => (bool) $row->staff_eligible,
            'updated_at' => $row->updated_at,
        ])->values()->all();

        return $this->privateResponse([
            'entitlements' => $page,
            'next_before_id' => $hasMore ? $page[count($page) - 1]['id'] : null,
        ]);
    }

    /**
     * A history view of terminal two-person access decisions. This is not
     * an authorization path and does not include invoice or payment data.
     */
    public function decisions(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(['approved', 'rejected'])],
            'staff_member_id' => ['missing'],
            'target_staff_member_id' => ['missing'],
            'requested_by_staff_member_id' => ['missing'],
            'reviewed_by_staff_member_id' => ['missing'],
            'request_id' => ['missing'],
            'sort' => ['missing'],
        ]);

        $limit = (int) ($filters['limit'] ?? 20);
        $query = DB::table('staff_finance_access_requests')
            ->whereIn('status', ['approved', 'rejected']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['before_id'])) {
            $query->where('id', '<', (int) $filters['before_id']);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get([
            'id', 'target_staff_member_id', 'requested_by_staff_member_id',
            'reviewed_by_staff_member_id', 'status', 'created_at', 'decided_at',
        ]);

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'target_staff_member_id' => (int) $row->target_staff_member_id,
            'requested_by_staff_member_id' => (int) $row->requested_by_staff_member_id,
            'reviewed_by_staff_member_id' => (int) $row->reviewed_by_staff_member_id,
            'status' => $row->status,
            'created_at' => $row->created_at,
            'decided_at' => $row->decided_at,
        ])->values()->all();

        return $this->privateResponse([
            'decisions' => $page,
            'next_before_id' => $hasMore ? $page[count($page) - 1]['id'] : null,
        ]);
    }

    private function authorizeAdministrator(Request $request): void
    {
        $staff = $request->user();
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;

        if (! $staff instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || $token->tokenable_type !== $staff->getMorphClass()
            || (string) $token->tokenable_id !== (string) $staff->getKey()) {
            abort(403);
        }

        Gate::forUser($staff)->authorize('staff-ads-assignments-admin', [$token]);
    }

    private function privateResponse(array $payload): JsonResponse
    {
        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
