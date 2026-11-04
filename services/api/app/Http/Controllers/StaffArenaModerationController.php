<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffArenaModerationController extends Controller
{
    public function __construct(
        private readonly SecurityAuditRecorder $audit,
    ) {
    }

    public function updateArenaControl(Request $request, int $arenaId): JsonResponse
    {
        $validated = $request->validate([
            'state' => [
                'required',
                'string',
                Rule::in(['scheduled', 'pre_match_open', 'live_open', 'post_match_open', 'archived', 'suspended']),
            ],
            'posting_enabled' => ['required', 'boolean'],
            'slow_mode_seconds' => ['required', 'integer', 'between:0,3600'],
            'emergency_lock_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'staff_member_id' => ['missing'],
        ]);

        return DB::transaction(function () use ($request, $arenaId, $validated): JsonResponse {
            $staff = $this->authorizeModerationStepUp($request);

            $arena = DB::table('match_arenas')
                ->where('id', $arenaId)
                ->lockForUpdate()
                ->first();

            if (! $arena) {
                return response()->json(['message' => 'Match arena not found.'], 404);
            }

            $isLockedState = $validated['state'] === 'suspended' || ! $validated['posting_enabled'];
            $now = now();

            DB::table('match_arenas')
                ->where('id', $arenaId)
                ->update([
                    'state' => $validated['state'],
                    'posting_enabled' => (bool) $validated['posting_enabled'],
                    'slow_mode_seconds' => (int) $validated['slow_mode_seconds'],
                    'emergency_locked_by_staff_id' => $isLockedState ? $staff->getKey() : null,
                    'emergency_lock_reason' => $isLockedState ? ($validated['emergency_lock_reason'] ?? null) : null,
                    'emergency_locked_at' => $isLockedState ? $now : null,
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                $staff,
                'moderation.arena.control_updated',
                'match_arena',
                $arenaId,
                $request->attributes->get('request_id'),
            );

            return response()->json([
                'arena' => [
                    'id' => $arenaId,
                    'state' => $validated['state'],
                    'posting_enabled' => (bool) $validated['posting_enabled'],
                    'slow_mode_seconds' => (int) $validated['slow_mode_seconds'],
                    'emergency_lock_reason' => $isLockedState ? ($validated['emergency_lock_reason'] ?? null) : null,
                ],
            ]);
        });
    }

    public function moderateComment(Request $request, int $commentId): JsonResponse
    {
        $validated = $request->validate([
            'moderation_state' => [
                'required',
                'string',
                Rule::in(['visible', 'quarantined', 'hidden', 'removed']),
            ],
            'moderation_reason' => ['required', 'string', 'min:3', 'max:120'],
            'staff_member_id' => ['missing'],
        ]);

        return DB::transaction(function () use ($request, $commentId, $validated): JsonResponse {
            $staff = $this->authorizeModerationStepUp($request);

            $comment = DB::table('arena_comments')
                ->where('id', $commentId)
                ->lockForUpdate()
                ->first();

            if (! $comment) {
                return response()->json(['message' => 'Comment not found.'], 404);
            }

            $now = now();
            DB::table('arena_comments')
                ->where('id', $commentId)
                ->update([
                    'moderation_state' => $validated['moderation_state'],
                    'moderation_reason' => $validated['moderation_reason'],
                    'moderated_by_staff_id' => $staff->getKey(),
                    'moderated_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                $staff,
                'moderation.comment.moderated',
                'arena_comment',
                $commentId,
                $request->attributes->get('request_id'),
            );

            return response()->json([
                'comment' => [
                    'id' => $commentId,
                    'moderation_state' => $validated['moderation_state'],
                    'moderation_reason' => $validated['moderation_reason'],
                ],
            ]);
        });
    }

    public function resolveReport(Request $request, int $reportId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['resolved', 'dismissed'])],
            'resolution_note' => ['required', 'string', 'min:3', 'max:255'],
            'comment_moderation_state' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['visible', 'quarantined', 'hidden', 'removed']),
            ],
            'staff_member_id' => ['missing'],
        ]);

        return DB::transaction(function () use ($request, $reportId, $validated): JsonResponse {
            $staff = $this->authorizeModerationStepUp($request);

            $report = DB::table('moderation_reports')
                ->where('id', $reportId)
                ->lockForUpdate()
                ->first();

            if (! $report) {
                return response()->json(['message' => 'Moderation report not found.'], 404);
            }

            if ($report->status !== 'open') {
                return response()->json(['message' => 'Moderation report is already closed.'], 409);
            }

            $now = now();
            DB::table('moderation_reports')
                ->where('id', $reportId)
                ->update([
                    'status' => $validated['status'],
                    'resolved_by_staff_id' => $staff->getKey(),
                    'resolution_note' => $validated['resolution_note'],
                    'resolved_at' => $now,
                    'updated_at' => $now,
                ]);

            if (! empty($validated['comment_moderation_state'])) {
                DB::table('arena_comments')
                    ->where('id', $report->arena_comment_id)
                    ->update([
                        'moderation_state' => $validated['comment_moderation_state'],
                        'moderation_reason' => 'report_resolution',
                        'moderated_by_staff_id' => $staff->getKey(),
                        'moderated_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            $this->audit->record(
                $staff,
                'moderation.report.resolved',
                'moderation_report',
                $reportId,
                $request->attributes->get('request_id'),
            );

            return response()->json([
                'report' => [
                    'id' => $reportId,
                    'status' => $validated['status'],
                    'arena_comment_id' => (int) $report->arena_comment_id,
                ],
            ]);
        });
    }

    private function authorizeModerationStepUp(Request $request): StaffMember
    {
        $principal = $request->user();
        $presented = $request->bearerToken();
        if (! $principal instanceof StaffMember || ! $presented) {
            abort(401, 'Unauthenticated.');
        }

        $staff = StaffMember::query()
            ->whereKey($principal->getKey())
            ->lockForUpdate()
            ->first();

        $token = PersonalAccessToken::findToken($presented);
        if (! $token) {
            abort(401, 'Unauthenticated.');
        }

        $lockedToken = PersonalAccessToken::query()
            ->whereKey($token->getKey())
            ->lockForUpdate()
            ->first();

        if (! $lockedToken || $lockedToken->expires_at === null || $lockedToken->expires_at->isPast()
            || $lockedToken->tokenable_type !== $principal->getMorphClass()
            || (string) $lockedToken->tokenable_id !== (string) $principal->getKey()) {
            abort(401, 'Unauthenticated.');
        }

        if (! $staff || ! $staff->is_active
            || $staff->email_verified_at === null
            || $staff->mfa_enrolled_at === null
            || ! in_array($staff->role, ['support', 'editor'], true)
            || ! $lockedToken->can('staff:session')
            || ! $lockedToken->can('staff:step-up')) {
            abort(403, 'Forbidden.');
        }

        return $staff;
    }
}
