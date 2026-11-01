<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Services\StaffMfaService;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffAuthController extends Controller
{
    public function login(Request $request, StaffMfaService $mfa, SecurityAuditRecorder $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'recovery_code' => ['nullable', 'string', 'regex:/^[0-9A-F]{32}$/'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $candidate = StaffMember::query()->where('email', $email)->first();
        if (! $candidate || ! $candidate->is_active || ! $candidate->mfa_enrolled_at
            || ! $candidate->email_verified_at || ! Hash::check($data['password'], $candidate->password)) {
            $audit->recordFailure(
                'staff.login.failed', $email, $candidate, $request->attributes->get('request_id'),
            );

            return response()->json(['message' => 'Invalid credentials or MFA.'], 401);
        }

        $token = DB::transaction(static function () use ($candidate, $data, $mfa, $audit, $request): ?string {
            $staff = StaffMember::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            if (! $staff->is_active || ! $staff->mfa_enrolled_at || ! $staff->email_verified_at
                || ! Hash::check($data['password'], $staff->password)
                || ! $mfa->consumeFactor($staff, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
                return null;
            }

            $token = $staff->createToken('staff-session', ['staff:session'], now()->addMinutes(15))->plainTextToken;
            $audit->record($staff, 'staff.session.started', 'staff_member', (int) $staff->getKey(), $request->attributes->get('request_id'));

            return $token;
        });

        if ($token === null) {
            $audit->recordFailure(
                'staff.login.failed', $email, $candidate, $request->attributes->get('request_id'),
            );

            return response()->json(['message' => 'Invalid credentials or MFA.'], 401);
        }

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in_seconds' => 900,
        ])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var StaffMember $staff */
        $staff = StaffMember::query()->findOrFail($request->user()->getKey());

        return response()->json([
            'staff' => [
                'id' => $staff->getKey(),
                'name' => $staff->name,
                'email' => $staff->email,
                'role' => $staff->role,
                'mfa_enrolled' => $staff->mfa_enrolled_at !== null,
            ],
        ]);
    }

    public function logout(Request $request, SecurityAuditRecorder $audit): JsonResponse
    {
        // The guard may cache the previous request's currentAccessToken in
        // long-lived workers and tests. Revoke the *presented* token only.
        $presented = $request->bearerToken();
        $token = $presented ? PersonalAccessToken::findToken($presented) : null;
        if (! $token || $token->tokenable_type !== $request->user()?->getMorphClass()
            || (string) $token->tokenable_id !== (string) $request->user()?->getKey()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::transaction(static function () use ($token, $request, $audit): void {
            $token->delete();
            $staff = $request->user();
            $audit->record($staff, 'staff.session.ended', 'staff_member', (int) $staff->getKey(), $request->attributes->get('request_id'));
        });

        return response()->json(['message' => 'Signed out.']);
    }

    public function stepUp(Request $request, StaffMfaService $mfa, SecurityAuditRecorder $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'recovery_code' => ['nullable', 'string', 'regex:/^[0-9A-F]{32}$/'],
        ]);
        $staff = $request->user();

        $token = DB::transaction(static function () use ($staff, $data, $mfa, $audit, $request): ?string {
            $locked = StaffMember::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || ! $locked->mfa_enrolled_at
                || ! $mfa->consumeFactor($locked, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
                return null;
            }

            $token = $locked->createToken(
                'staff-step-up',
                ['staff:session', 'staff:step-up'],
                now()->addMinutes(5),
            )->plainTextToken;
            $audit->record($locked, 'staff.session.stepped_up', 'staff_member', (int) $locked->getKey(), $request->attributes->get('request_id'));

            return $token;
        });

        if ($token === null) {
            $audit->recordFailure(
                'staff.step_up.failed', $staff->email, $staff, $request->attributes->get('request_id'),
            );

            return response()->json(['message' => 'Invalid MFA.'], 401);
        }

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in_seconds' => 300,
        ])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
