<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Services\StaffMfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffAuthController extends Controller
{
    public function login(Request $request, StaffMfaService $mfa): JsonResponse
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
            return response()->json(['message' => 'Invalid credentials or MFA.'], 401);
        }

        $token = DB::transaction(static function () use ($candidate, $data, $mfa): ?string {
            $staff = StaffMember::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            if (! $staff->is_active || ! $staff->mfa_enrolled_at || ! $staff->email_verified_at
                || ! Hash::check($data['password'], $staff->password)
                || ! $mfa->consumeFactor($staff, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
                return null;
            }

            return $staff->createToken('staff-session', ['staff:session'], now()->addMinutes(15))->plainTextToken;
        });

        if ($token === null) {
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
        $staff = $request->user();

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

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $token->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function stepUp(Request $request, StaffMfaService $mfa): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'recovery_code' => ['nullable', 'string', 'regex:/^[0-9A-F]{32}$/'],
        ]);
        $staff = $request->user();

        $token = DB::transaction(static function () use ($staff, $data, $mfa): ?string {
            $locked = StaffMember::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || ! $locked->mfa_enrolled_at
                || ! $mfa->consumeFactor($locked, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
                return null;
            }

            return $locked->createToken(
                'staff-step-up',
                ['staff:session', 'staff:step-up'],
                now()->addMinutes(5),
            )->plainTextToken;
        });

        if ($token === null) {
            return response()->json(['message' => 'Invalid MFA.'], 401);
        }

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in_seconds' => 300,
        ])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
