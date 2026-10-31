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
        $member = $request->user();
        if (! $member instanceof StaffMember
            || ! $member->is_active
            || $member->email_verified_at === null
            || $member->mfa_enrolled_at === null
            || ! $member->currentAccessToken() instanceof PersonalAccessToken
            || ! $member->tokenCan('staff:session')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
