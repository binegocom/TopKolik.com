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

final class StaffFinanceAccessApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['missing'],
            'target_staff_member_id' => ['missing'],
            'requested_by_staff_member_id' => ['missing'],
        ]);

        $limit = (int) ($filters['limit'] ?? 20);
        $query = DB::table('staff_finance_access_requests')->where('status', 'pending');
        if (isset($filters['before_id'])) {
            $query->where('id', '<', (int) $filters['before_id']);
        }
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get([
            'id', 'target_staff_member_id', 'requested_by_staff_member_id',
            'expires_at', 'created_at',
        ]);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'target_staff_member_id' => (int) $row->target_staff_member_id,
            'requested_by_staff_member_id' => (int) $row->requested_by_staff_member_id,
            'expires_at' => $row->expires_at,
            'created_at' => $row->created_at,
        ])->values()->all();

        return $this->privateResponse([
            'requests' => $page,
            'next_before_id' => $hasMore ? $page[count($page) - 1]['id'] : null,
        ]);
    }

    public function store(Request $request, SecurityAuditRecorder $audit): JsonResponse
    {
        [$actor, $token] = $this->admin($request);
        $data = $request->validate([
            'target_staff_member_id' => ['required', 'integer', 'min:1'],
            'requested_by_staff_member_id' => ['missing'],
            'reviewed_by_staff_member_id' => ['missing'],
            'status' => ['missing'],
            'is_active' => ['missing'],
            'role' => ['missing'],
            'amount' => ['missing'],
        ]);

        $targetId = (int) $data['target_staff_member_id'];
        if ($targetId === (int) $actor->getKey()) {
            abort(403, 'Self-provisioning is forbidden.');
        }

        $requestId = DB::transaction(function () use ($request, $audit, $actor, $token, $targetId): int {
            $this->lockAdminGrant($actor, $token);
            $target = StaffMember::query()->whereKey($targetId)->lockForUpdate()->first();
            if (! $target || ! $target->is_active || $target->role !== 'finance'
                || ! $target->email_verified_at || ! $target->mfa_enrolled_at) {
                abort(404);
            }

            if (DB::table('staff_finance_entitlements')
                ->where('staff_member_id', $targetId)->where('is_active', true)->exists()) {
                abort(409, 'Finance access is already active.');
            }
            if (DB::table('staff_finance_access_requests')
                ->where('target_staff_member_id', $targetId)->where('status', 'pending')->exists()) {
                abort(409, 'Finance access request is already pending.');
            }

            $id = (int) DB::table('staff_finance_access_requests')->insertGetId([
                'target_staff_member_id' => $targetId,
                'requested_by_staff_member_id' => $actor->getKey(),
                'status' => 'pending',
                'expires_at' => now()->addDay(),
                'requested_request_id' => $request->attributes->get('request_id'),
                'created_at' => now(),
            ]);

            $audit->record(
                $actor, 'staff.finance_access.requested', 'staff_member',
                $targetId, $request->attributes->get('request_id'),
            );
            return $id;
        }, 3);

        return $this->privateResponse([
            'request' => ['id' => $requestId, 'status' => 'pending'],
        ], 201);
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
        [$actor, $token] = $this->admin($request);
        $request->validate([
            'target_staff_member_id' => ['missing'],
            'requested_by_staff_member_id' => ['missing'],
            'reviewed_by_staff_member_id' => ['missing'],
            'status' => ['missing'],
            'is_active' => ['missing'],
            'role' => ['missing'],
            'amount' => ['missing'],
        ]);

        $status = DB::transaction(function () use ($request, $approval, $audit, $actor, $token, $grant): string {
            $this->lockAdminGrant($actor, $token);

            $pending = DB::table('staff_finance_access_requests')
                ->where('id', $approval)->lockForUpdate()->first();
            if (! $pending) {
                abort(404);
            }
            if ($pending->status !== 'pending') {
                abort(409, 'Finance access request has already been decided.');
            }
            if ((int) $pending->requested_by_staff_member_id === (int) $actor->getKey()
                || (int) $pending->target_staff_member_id === (int) $actor->getKey()) {
                abort(403, 'A different administrator is required.');
            }

            if ($grant) {
                if (now()->greaterThanOrEqualTo($pending->expires_at)) {
                    abort(409, 'Finance access request expired.');
                }

                $requester = StaffMember::query()
                    ->whereKey($pending->requested_by_staff_member_id)->lockForUpdate()->first();
                if (! $requester || ! $requester->is_active || ! $requester->email_verified_at
                    || ! $requester->mfa_enrolled_at || $requester->role === 'ads'
                    || ! DB::table('staff_security_admin_grants')
                        ->where('staff_member_id', $requester->getKey())
                        ->where('is_active', true)->lockForUpdate()->first()) {
                    abort(409, 'Requesting administrator is no longer authorized.');
                }

                $target = StaffMember::query()
                    ->whereKey($pending->target_staff_member_id)->lockForUpdate()->first();
                if (! $target || ! $target->is_active || $target->role !== 'finance'
                    || ! $target->email_verified_at || ! $target->mfa_enrolled_at) {
                    abort(409, 'Finance staff member is no longer eligible.');
                }

                $existing = DB::table('staff_finance_entitlements')
                    ->where('staff_member_id', $target->getKey())->lockForUpdate()->first();
                if ($existing && $existing->is_active) {
                    abort(409, 'Finance access is already active.');
                }

                if ($existing) {
                    DB::table('staff_finance_entitlements')
                        ->where('id', $existing->id)->where('is_active', false)
                        ->update([
                            'is_active' => true,
                            'last_approved_by_staff_id' => $actor->getKey(),
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('staff_finance_entitlements')->insert([
                        'staff_member_id' => $target->getKey(),
                        'is_active' => true,
                        'last_approved_by_staff_id' => $actor->getKey(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $decision = $grant ? 'approved' : 'rejected';
            $updated = DB::table('staff_finance_access_requests')
                ->where('id', $approval)->where('status', 'pending')
                ->update([
                    'status' => $decision,
                    'reviewed_by_staff_member_id' => $actor->getKey(),
                    'decided_at' => now(),
                    'decided_request_id' => $request->attributes->get('request_id'),
                ]);
            if ($updated !== 1) {
                abort(409, 'Finance access decision conflict.');
            }

            $audit->record(
                $actor,
                $grant ? 'staff.finance_access.approved' : 'staff.finance_access.rejected',
                'staff_member', (int) $pending->target_staff_member_id,
                $request->attributes->get('request_id'),
            );
            return $decision;
        }, 3);

        return $this->privateResponse(['request' => ['id' => $approval, 'status' => $status]]);
    }

    private function lockAdminGrant(StaffMember $admin, PersonalAccessToken $token): void
    {
        $row = DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $admin->getKey())->lockForUpdate()->first();
        if (! $row || ! $row->is_active) {
            abort(403);
        }
        Gate::forUser($admin)->authorize('staff-ads-assignments-admin', [$token]);
    }

    /** @return array{StaffMember, PersonalAccessToken} */
    private function admin(Request $request): array
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
        return [$actor, $token];
    }

    private function privateResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
