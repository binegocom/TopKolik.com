<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\StaffResourceContext;
use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffAdsAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$staff] = $this->session($request);

        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'staff_member_id' => ['missing'],
            'advertiser_organization_id' => ['missing'],
            'include_inactive' => ['missing'],
            'is_active' => ['missing'],
        ]);
        $limit = (int) ($filters['limit'] ?? 20);
        $query = DB::table('staff_advertiser_assignments as a')
            ->join('advertiser_organizations as o', 'o.id', '=', 'a.advertiser_organization_id')
            ->where('a.staff_member_id', $staff->getKey())
            ->where('a.is_active', true)
            ->where('o.is_active', true);

        if (array_key_exists('before_id', $filters)) {
            $query->where('a.id', '<', (int) $filters['before_id']);
        }

        $rows = $query->orderByDesc('a.id')->limit($limit + 1)
            ->get(['a.id', 'o.id as organization_id', 'o.name as organization_name']);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'organization_id' => (int) $row->organization_id,
            'organization_name' => $row->organization_name,
        ])->values()->all();

        return $this->privateResponse([
            'assignments' => $page,
            'next_before_id' => $hasMore ? $page[count($page) - 1]['id'] : null,
        ]);
    }

    public function relinquish(
        Request $request,
        int $organization,
        SecurityAuditRecorder $audit,
    ): JsonResponse {
        [$staff, $token] = $this->session($request);

        // A successful staff login is insufficient for voluntary removal.
        // This must use the short-lived MFA step-up session.
        if (! $token->can('staff:step-up')) {
            abort(403);
        }

        $request->validate([
            'staff_member_id' => ['missing'],
            'advertiser_organization_id' => ['missing'],
            'is_active' => ['missing'],
            'role' => ['missing'],
            'grant' => ['missing'],
        ]);

        DB::transaction(function () use ($request, $staff, $token, $organization, $audit): void {
            $assignment = DB::table('staff_advertiser_assignments as a')
                ->join('advertiser_organizations as o', 'o.id', '=', 'a.advertiser_organization_id')
                ->where('a.staff_member_id', $staff->getKey())
                ->where('a.advertiser_organization_id', $organization)
                ->where('a.is_active', true)
                ->where('o.is_active', true)
                ->select(['a.id'])
                ->lockForUpdate()->first();

            // Prevent disclosure of arbitrary organizations or other staff.
            if (! $assignment) {
                abort(404);
            }

            Gate::forUser($staff)->authorize('staff-resource', [
                'advertising.organization.review',
                new StaffResourceContext(
                    $organization, 'advertiser_organization', 'active',
                    advertiserId: $organization,
                ),
                $token,
            ]);

            $changed = DB::table('staff_advertiser_assignments')
                ->where('id', $assignment->id)
                ->where('staff_member_id', $staff->getKey())
                ->where('advertiser_organization_id', $organization)
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            if ($changed !== 1) {
                abort(409, 'Assignment already relinquished.');
            }

            // Roll back the assignment change if durable audit insertion fails.
            $audit->record(
                $staff,
                'staff.advertiser_assignment.relinquished',
                'advertiser_organization',
                $organization,
                $request->attributes->get('request_id'),
            );
        });

        return $this->privateResponse([
            'organization_id' => $organization,
            'assignment_status' => 'relinquished',
        ]);
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

        Gate::forUser($staff)->authorize('staff-ads-assignments-self', [$token]);

        return [$staff, $token];
    }

    private function privateResponse(array $response): JsonResponse
    {
        return response()->json($response)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
