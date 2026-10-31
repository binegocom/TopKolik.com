<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffInvitation;
use App\Models\StaffMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

final class StaffInvitationAcceptanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invitation' => ['required', 'uuid'],
            'token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            // The server alone controls invitation email, role and activation.
            'email' => ['missing'],
            'role' => ['missing'],
            'is_active' => ['missing'],
            'mfa_enrolled_at' => ['missing'],
            'user_id' => ['missing'],
        ]);

        try {
            $setupToken = DB::transaction(static function () use ($data): ?string {
                $invitation = StaffInvitation::query()->whereKey($data['invitation'])
                    ->lockForUpdate()->first();

                if (! $invitation || $invitation->accepted_at !== null
                    || $invitation->expires_at->isPast()
                    || ! hash_equals($invitation->token_hash, hash('sha256', $data['token']))
                    || StaffMember::query()->where('email', $invitation->email)->exists()) {
                    return null;
                }

                $token = bin2hex(random_bytes(32));

                StaffMember::create([
                    'name' => trim($data['name']),
                    'email' => $invitation->email,
                    'password' => $data['password'],
                    'role' => $invitation->role,
                ])->forceFill([
                    'email_verified_at' => now(),
                    'is_active' => false,
                    'mfa_enrolled_at' => null,
                    'mfa_setup_token_hash' => hash('sha256', $token),
                    'mfa_setup_expires_at' => now()->addMinutes(15),
                ])->save();

                $invitation->forceFill(['accepted_at' => now()])->save();

                return $token;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $setupToken = null;
        }

        if ($setupToken === null) {
            return response()->json(['message' => 'Invalid or expired invitation.'], 422);
        }

        return response()->json([
            'message' => 'Invitation accepted. Staff access requires MFA enrollment and approval.',
            'mfa_setup_token' => $setupToken,
            'mfa_setup_expires_in_seconds' => 900,
        ], 202)->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
