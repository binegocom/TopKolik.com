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
use Throwable;

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
            $accepted = DB::transaction(static function () use ($data): bool {
                $invitation = StaffInvitation::query()->whereKey($data['invitation'])
                    ->lockForUpdate()->first();

                if (! $invitation || $invitation->accepted_at !== null
                    || $invitation->expires_at->isPast()
                    || ! hash_equals($invitation->token_hash, hash('sha256', $data['token']))
                    || StaffMember::query()->where('email', $invitation->email)->exists()) {
                    return false;
                }

                StaffMember::create([
                    'name' => trim($data['name']),
                    'email' => $invitation->email,
                    'password' => $data['password'],
                    'role' => $invitation->role,
                ])->forceFill([
                    'email_verified_at' => now(),
                    'is_active' => false,
                    'mfa_enrolled_at' => null,
                ])->save();

                $invitation->forceFill(['accepted_at' => now()])->save();

                return true;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $accepted = false;
        }

        if (! $accepted) {
            return response()->json(['message' => 'Invalid or expired invitation.'], 422);
        }

        return response()->json([
            'message' => 'Invitation accepted. Staff access requires MFA enrollment and approval.',
        ], 202);
    }
}
