<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Services\StaffMfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StaffMfaEnrollmentController extends Controller
{
    public function setup(Request $request, StaffMfaService $service): JsonResponse
    {
        $data = $request->validate(['setup_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/']]);

        $response = DB::transaction(static function () use ($data, $service): ?array {
            $member = self::findLockedMember($data['setup_token']);

            if (! $member || $member->mfa_enrolled_at || $member->is_active) {
                return null;
            }

            if (! $member->mfa_pending_secret) {
                $member->forceFill(['mfa_pending_secret' => $service->generateSecret()])->save();
            }

            return [
                'secret' => $member->mfa_pending_secret,
                'provisioning_uri' => $service->provisioningUri($member),
                'expires_at' => $member->mfa_setup_expires_at?->toIso8601String(),
            ];
        });

        if ($response === null) {
            return response()->json(['message' => 'Invalid or expired enrollment.'], 422);
        }

        return response()->json($response)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    public function confirm(Request $request, StaffMfaService $service): JsonResponse
    {
        $data = $request->validate([
            'setup_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $codes = DB::transaction(static function () use ($data, $service): ?array {
            $member = self::findLockedMember($data['setup_token']);

            if (! $member || $member->mfa_enrolled_at || ! $member->mfa_pending_secret
                || $member->is_active || ! $service->confirmPendingCode($member, $data['code'])) {
                return null;
            }

            $member->forceFill([
                'mfa_secret' => $member->mfa_pending_secret,
                'mfa_pending_secret' => null,
                'mfa_setup_token_hash' => null,
                'mfa_setup_expires_at' => null,
                'mfa_enrolled_at' => now(),
                'mfa_last_step' => $service->lastVerifiedStep(),
            ])->save();

            return $service->issueRecoveryCodes($member);
        });

        if ($codes === null) {
            return response()->json(['message' => 'Invalid or expired enrollment.'], 422);
        }

        return response()->json([
            'message' => 'MFA enrolled. Staff access remains pending operator approval.',
            'recovery_codes' => $codes,
        ])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private static function findLockedMember(string $token): ?StaffMember
    {
        $member = StaffMember::query()
            ->where('mfa_setup_token_hash', hash('sha256', $token))
            ->lockForUpdate()
            ->first();

        if (! $member || ! $member->mfa_setup_expires_at
            || $member->mfa_setup_expires_at->isPast()) {
            return null;
        }

        return $member;
    }
}
