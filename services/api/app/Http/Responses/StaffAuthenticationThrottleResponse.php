<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\StaffMember;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Named rate-limit responses run before the authentication controller.
 * Only validated, pseudonymized identifiers enter the append-only ledger.
 */
final class StaffAuthenticationThrottleResponse
{
    public function __construct(private readonly SecurityAuditRecorder $audit)
    {
    }

    public function login(Request $request, array $headers): JsonResponse
    {
        $rawEmail = $request->input('email');

        if (is_string($rawEmail)) {
            $email = mb_strtolower(trim($rawEmail));

            if (mb_strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                // Do not look up staff here: account existence must not affect
                // the response or disclose a principal through this path.
                $this->audit->recordFailure(
                    'staff.login.failed',
                    $email,
                    null,
                    $request->attributes->get('request_id'),
                );
            }
        }

        return $this->throttled($headers);
    }

    public function stepUp(Request $request, array $headers): JsonResponse
    {
        // Never invent an actor from an invalid or reader bearer token.
        $principal = $request->user();
        if ($principal instanceof StaffMember) {
            $this->audit->recordFailure(
                'staff.step_up.failed',
                $principal->email,
                $principal,
                $request->attributes->get('request_id'),
            );
        }

        return $this->throttled($headers);
    }

    private function throttled(array $headers): JsonResponse
    {
        // Preserve Laravel's Retry-After and rate-limit headers, but never
        // return credentials, email identifiers or authentication state.
        return response()->json(['message' => 'Too Many Attempts.'], 429)
            ->withHeaders($headers)
            ->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
