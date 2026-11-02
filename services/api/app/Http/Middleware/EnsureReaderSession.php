<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class EnsureReaderSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sanctum abilities are not a principal-type boundary: staff and
        // advertiser tokens can carry identical ability strings.
        $reader = $request->user();
        $presented = $request->bearerToken();
        $token = $presented ? PersonalAccessToken::findToken($presented) : null;

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $reader instanceof User
            || $token->tokenable_type !== $reader->getMorphClass()
            || (string) $token->tokenable_id !== (string) $reader->getKey()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Legacy tokens without an expiry are not valid mobile sessions.
        // Never infer session validity from the Sanctum ability alone.
        if ($token->expires_at === null || $token->expires_at->isPast()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $fresh = User::query()->whereKey($reader->getKey())->first();
        if (! $fresh || ! $fresh->hasVerifiedEmail()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $response = $next($request);

        // Reader profiles, consent records and favorites are account data.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
