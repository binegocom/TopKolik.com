<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\StaffMember;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class EnsureStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // Never rely only on the in-memory guard user. Check the presented
        // bearer token against the persisted token table on every request:
        // logout, emergency revocation and deactivation must take effect now.
        $presented = $request->bearerToken();
        $token = $presented ? PersonalAccessToken::findToken($presented) : null;

        if (! $token || $token->expires_at?->isPast()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $member = $request->user();
        if (! $member instanceof StaffMember
            || $member->getMorphClass() !== $token->tokenable_type
            || (string) $member->getKey() !== (string) $token->tokenable_id
            || ! $token->can('staff:session')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $fresh = StaffMember::query()->whereKey($member->getKey())->first();
        if (! $fresh || ! $fresh->is_active
            || $fresh->email_verified_at === null
            || $fresh->mfa_enrolled_at === null) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
