<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffSecurityAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var StaffMember $staff */
        $staff = $request->user();
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Validate even the optional actor filter: no route/query parameter
        // may select another staff member. Unrecognized query keys are unused.
        $data = $request->validate([
            'staff_member_id' => ['missing'],
            'actor_id' => ['missing'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', Rule::in(SecurityAuditRecorder::allowedActions())],
            'subject_type' => ['sometimes', 'string', Rule::in(SecurityAuditRecorder::allowedSubjectTypes())],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'request_id' => ['sometimes', 'uuid'],
        ]);

        Gate::forUser($staff)->authorize('staff-audit-self', [$token]);

        $limit = (int) ($data['limit'] ?? 20);
        $query = DB::table('security_audit_events')
            ->where('staff_member_id', $staff->getKey());

        foreach (['action', 'subject_type', 'subject_id', 'request_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $query->where($field, $data[$field]);
            }
        }
        if (array_key_exists('before_id', $data)) {
            $query->where('id', '<', (int) $data['before_id']);
        }

        // Keyset pagination avoids large offsets and keeps filtering scoped
        // to the authenticated staff ID at the SQL boundary.
        $rows = $query->orderByDesc('id')->limit($limit + 1)
            ->get(['id', 'action', 'subject_type', 'subject_id', 'request_id', 'created_at']);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->map(static fn (object $event): array => [
            'id' => (int) $event->id,
            'action' => $event->action,
            'subject_type' => $event->subject_type,
            'subject_id' => (int) $event->subject_id,
            'request_id' => $event->request_id,
            'created_at' => $event->created_at,
        ])->values()->all();

        return response()->json([
            'events' => $page,
            'next_before_id' => $hasMore ? $page[count($page) - 1]['id'] : null,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
