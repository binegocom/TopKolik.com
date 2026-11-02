<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class EnsureStaffSession
{
    public function __construct(private readonly SecurityAuditRecorder $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Never rely only on the in-memory guard user. Check the presented
        // bearer token against the persisted token table on every request:
        // logout, emergency revocation and deactivation must take effect now.
        $presented = $request->bearerToken();
        $token = $presented ? PersonalAccessToken::findToken($presented) : null;

        // A staff bearer token without an explicit expiry is never a session.
        if (! $token || $token->expires_at === null || $token->expires_at->isPast()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $member = $request->user();
        if (! $member instanceof StaffMember
            || $member->getMorphClass() !== $token->tokenable_type
            || (string) $member->getKey() !== (string) $token->tokenable_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $token->can('staff:session')) {
            $this->auditDeniedStepUp($request, $member);

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $fresh = StaffMember::query()->whereKey($member->getKey())->first();
        if (! $fresh || ! $fresh->is_active
            || $fresh->email_verified_at === null
            || $fresh->mfa_enrolled_at === null) {
            // Only a verified, persisted staff token can establish the actor.
            // Missing staff records cannot satisfy the audit foreign key.
            if ($fresh) {
                $this->auditDeniedStepUp($request, $fresh);
            }

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }

    private function auditDeniedStepUp(Request $request, StaffMember $member): void
    {
        // Other staff routes are not MFA attempts. Do not create a failure
        // from an anonymous, reader, expired or mismatched bearer token.
        if (! $request->is('api/v1/staff/auth/step-up')) {
            return;
        }

        $this->audit->recordFailure(
            'staff.step_up.failed',
            $member->email,
            $member,
            $request->attributes->get('request_id'),
        );
    }
}
