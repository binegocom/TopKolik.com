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

final class StaffAdsAssignmentApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['missing'],
            'staff_member_id' => ['missing'],
            'advertiser_organization_id' => ['missing'],
        ]);

        $limit = (int) ($filters['limit'] ?? 20);
        $query = DB::table('staff_ads_assignment_approvals')->where('status', 'pending');
        if (array_key_exists('before_id', $filters)) {
            $query->where('id', '<', (int) $filters['before_id']);
        }
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get([
            'id', 'target_staff_member_id', 'advertiser_organization_id',
            'requested_by_staff_member_id', 'expires_at', 'created_at',
        ]);
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'target_staff_member_id' => (int) $row->target_staff_member_id,
            'advertiser_organization_id' => (int) $row->advertiser_organization_id,
            'requested_by_staff_member_id' => (int) $row->requested_by_staff_member_id,
            'expires_at' => $row->expires_at,
            'created_at' => $row->created_at,
        ])->values()->all();

        return $this->privateResponse([
            'requests' => $items,
            'next_before_id' => $hasMore ? $items[count($items) - 1]['id'] : null,
        ]);
    }

    public function store(Request $request, SecurityAuditRecorder $audit): JsonResponse
    {
        $admin = $this->admin($request);
        $data = $request->validate([
            'target_staff_member_id' => ['required', 'integer', 'min:1'],
            'advertiser_organization_id' => ['required', 'integer', 'min:1'],
            'requested_by_staff_member_id' => ['missing'],
            'approved_by' => ['missing'],
            'status' => ['missing'],
            'is_active' => ['missing'],
            'role' => ['missing'],
        ]);
        $targetId = (int) $data['target_staff_member_id'];
        $orgId = (int) $data['advertiser_organization_id'];

        if ($targetId === (int) $admin->getKey()) {
            abort(403);
        }

        $id = DB::transaction(function () use ($request, $audit, $admin, $targetId, $orgId): int {
            $this->assertCurrentAdminGrantLocked($request, $admin);
            $target = StaffMember::query()->whereKey($targetId)->lockForUpdate()->first();
            $organization = DB::table('advertiser_organizations')
                ->where('id', $orgId)->lockForUpdate()->first();

            if (! $target || ! $target->is_active || $target->role !== 'ads'
                || ! $target->email_verified_at || ! $target->mfa_enrolled_at
                || ! $organization || ! $organization->is_active) {
                abort(404);
            }

            if (DB::table('staff_advertiser_assignments')
                ->where('staff_member_id', $targetId)
                ->where('advertiser_organization_id', $orgId)
                ->where('is_active', true)->exists()) {
                abort(409, 'An active assignment already exists.');
            }

            if (DB::table('staff_ads_assignment_approvals')
                ->where('target_staff_member_id', $targetId)
                ->where('advertiser_organization_id', $orgId)
                ->where('status', 'pending')->exists()) {
                abort(409, 'An assignment request is already pending.');
            }

            $id = (int) DB::table('staff_ads_assignment_approvals')->insertGetId([
                'target_staff_member_id' => $targetId,
                'advertiser_organization_id' => $orgId,
                'requested_by_staff_member_id' => $admin->getKey(),
                'status' => 'pending',
                'requested_request_id' => $request->attributes->get('request_id'),
                'expires_at' => now()->addDay(),
                'created_at' => now(),
            ]);
            $audit->record($admin, 'staff.advertiser_assignment.requested',
                'advertiser_organization', $orgId, $request->attributes->get('request_id'));

            return $id;
        }, attempts: 3);

        return $this->privateResponse(['request' => [
            'id' => $id,
            'status' => 'pending',
        ]], 201);
    }

    public function approve(Request $request, int $approval, SecurityAuditRecorder $audit): JsonResponse
    {
        return $this->decide($request, $approval, $audit, true);
    }

    public function reject(Request $request, int $approval, SecurityAuditRecorder $audit): JsonResponse
    {
        return $this->decide($request, $approval, $audit, false);
    }

    private function decide(
        Request $request,
        int $approval,
        SecurityAuditRecorder $audit,
        bool $grant,
    ): JsonResponse {
        $admin = $this->admin($request);
        $request->validate([
            'staff_member_id' => ['missing'],
            'advertiser_organization_id' => ['missing'],
            'requested_by_staff_member_id' => ['missing'],
            'reviewed_by_staff_member_id' => ['missing'],
            'status' => ['missing'],
            'role' => ['missing'],
            'is_active' => ['missing'],
        ]);

        $decision = DB::transaction(function () use ($request, $admin, $approval, $audit, $grant): array {
            $this->assertCurrentAdminGrantLocked($request, $admin);
            $pending = DB::table('staff_ads_assignment_approvals')
                ->where('id', $approval)->lockForUpdate()->first();
            if (! $pending) {
                abort(404);
            }
            if ($pending->status !== 'pending') {
                abort(409, 'Assignment request already decided.');
            }
            if ((int) $pending->requested_by_staff_member_id === (int) $admin->getKey()
                || (int) $pending->target_staff_member_id === (int) $admin->getKey()) {
                abort(403, 'Separate approver required.');
            }
            if ($grant && now()->greaterThanOrEqualTo($pending->expires_at)) {
                abort(409, 'Assignment request expired; reject and resubmit.');
            }

            // Prevent approval of a request made by an operator whose access
            // has subsequently been withdrawn.
            if ($grant) {
                $requester = StaffMember::query()->find($pending->requested_by_staff_member_id);
                if (! $requester || ! $requester->is_active || ! $requester->mfa_enrolled_at
                    || ! $requester->email_verified_at || $requester->role === 'ads'
                    || ! DB::table('staff_security_admin_grants')
                        ->where('staff_member_id', $requester->getKey())
                        ->where('is_active', true)->exists()) {
                    abort(409, 'Requesting administrator no longer authorized.');
                }

                $target = StaffMember::query()->whereKey($pending->target_staff_member_id)
                    ->lockForUpdate()->first();
                $organization = DB::table('advertiser_organizations')
                    ->where('id', $pending->advertiser_organization_id)
                    ->lockForUpdate()->first();
                if (! $target || ! $target->is_active || $target->role !== 'ads'
                    || ! $target->email_verified_at || ! $target->mfa_enrolled_at
                    || ! $organization || ! $organization->is_active) {
                    abort(409, 'Assigned staff or advertiser organization is no longer eligible.');
                }

                $existing = DB::table('staff_advertiser_assignments')
                    ->where('staff_member_id', $target->getKey())
                    ->where('advertiser_organization_id', $pending->advertiser_organization_id)
                    ->lockForUpdate()->first();
                if ($existing && $existing->is_active) {
                    abort(409, 'Assignment is already active.');
                }

                if ($existing) {
                    DB::table('staff_advertiser_assignments')
                        ->where('id', $existing->id)->where('is_active', false)
                        ->update(['is_active' => true, 'updated_at' => now()]);
                } else {
                    DB::table('staff_advertiser_assignments')->insert([
                        'staff_member_id' => $target->getKey(),
                        'advertiser_organization_id' => $pending->advertiser_organization_id,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $status = $grant ? 'approved' : 'rejected';
            $changed = DB::table('staff_ads_assignment_approvals')
                ->where('id', $approval)->where('status', 'pending')
                ->update([
                    'status' => $status,
                    'reviewed_by_staff_member_id' => $admin->getKey(),
                    'decided_request_id' => $request->attributes->get('request_id'),
                    'decided_at' => now(),
                ]);
            if ($changed !== 1) {
                abort(409, 'Assignment request decision conflict.');
            }

            $audit->record($admin, $grant
                ? 'staff.advertiser_assignment.approved'
                : 'staff.advertiser_assignment.rejected',
                'advertiser_organization', (int) $pending->advertiser_organization_id,
                $request->attributes->get('request_id'));

            return ['id' => $approval, 'status' => $status];
        }, attempts: 3);

        return $this->privateResponse(['request' => $decision]);
    }

    private function assertCurrentAdminGrantLocked(Request $request, StaffMember $admin): void
    {
        // A grant revoked while the request was waiting must not authorize a
        // new approval or request. Share the lock with self-revocation.
        $grant = DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $admin->getKey())
            ->lockForUpdate()->first();

        if (! $grant || ! $grant->is_active) {
            abort(403);
        }

        $raw = $request->bearerToken();
        $token = $raw ? PersonalAccessToken::findToken($raw) : null;
        if (! $token) {
            abort(403);
        }
        Gate::forUser($admin)->authorize('staff-ads-assignments-admin', [$token]);
    }

    private function admin(Request $request): StaffMember
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

        return $staff;
    }

    private function privateResponse(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
