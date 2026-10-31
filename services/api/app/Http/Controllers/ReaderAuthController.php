<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class ReaderAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $email = mb_strtolower(trim($data['email']));
        if (! User::where('email', $email)->exists()) {
            try {
                $user = User::create([
                    'name' => trim($data['name']),
                    'email' => $email,
                    'password' => $data['password'],
                ]);
                event(new Registered($user));
            } catch (UniqueConstraintViolationException) {
                // Identical response prevents registration race disclosure.
            }
        }

        return response()->json(['message' => 'If eligible, an email verification link has been sent.'], 202);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string'],
        ]);
        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Invalid credentials or unverified account.'], 401);
        }

        // Mobile API tokens are never intended for storage in browser localStorage.
        $token = $user->createToken('reader-mobile', ['reader:read'], now()->addDays(30));

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            return response()->json(['message' => 'Bearer token required.'], 403);
        }

        $token->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If eligible, an email verification link has been sent.']);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        ];
    }
}
