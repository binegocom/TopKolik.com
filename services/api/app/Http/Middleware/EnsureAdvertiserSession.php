<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdvertiserAccount;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdvertiserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->bearerToken();
        $token = $presented ? PersonalAccessToken::findToken($presented) : null;
        $account = $request->user();

        if (! $token || $token->expires_at === null || $token->expires_at->isPast()) {
            return $this->privateResponse(response()->json(['message' => 'Unauthenticated.'], 401));
        }

        if (! $account instanceof AdvertiserAccount
            || $token->tokenable_type !== $account->getMorphClass()
            || (string) $token->tokenable_id !== (string) $account->getKey()
            || ! $token->can('advertiser:session')) {
            return $this->privateResponse(response()->json(['message' => 'Forbidden.'], 403));
        }

        // Changes to identity state must revoke access immediately, including
        // under long-lived workers or previously resolved auth guards.
        $fresh = AdvertiserAccount::query()->find($account->getKey());
        if (! $fresh || ! $fresh->is_active || $fresh->email_verified_at === null) {
            return $this->privateResponse(response()->json(['message' => 'Forbidden.'], 403));
        }

        return $this->privateResponse($next($request));
    }

    private function privateResponse(Response $response): Response
    {
        // Organization membership and campaign data are private, including
        // authorization failures that may be returned to a prior session.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
