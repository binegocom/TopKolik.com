<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

final class ReaderPasswordResetController extends Controller
{
    private const GENERIC_MESSAGE = 'If the account is eligible, reset instructions have been sent.';

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
        ]);

        // The configured URL is never taken from the HTTP Host or a request parameter.
        // Disable this endpoint for everyone until the trusted frontend route exists.
        if (! self::resetUrlConfigured()) {
            return response()->json(['message' => 'Password reset is temporarily unavailable.'], 503);
        }

        Password::broker('users')->sendResetLink([
            'email' => mb_strtolower(trim($data['email'])),
        ]);

        // Unknown accounts, throttled accounts and eligible accounts receive the
        // same status/body; the broker still limits token generation.
        return response()->json(['message' => self::GENERIC_MESSAGE], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));

        // Serialize resets of the same account to prevent concurrent single-use
        // token acceptance; only the user row is locked, never an entire table.
        $status = DB::transaction(function () use ($data): string {
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::broker('users')->reset($data, function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke every reader device, plus any database-backed browser sessions.
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->getKey())->delete();

                DB::afterCommit(static function () use ($user): void {
                    event(new PasswordReset($user));
                });
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Invalid or expired password reset request.'], 422);
        }

        return response()->json(['message' => 'Password updated. Sign in again on all devices.']);
    }

    private static function resetUrlConfigured(): bool
    {
        $url = config('auth.reader_password_reset_url');
        if (! is_string($url) || $url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (($parts['path'] ?? '/') === '/') {
            return false;
        }

        if ($parts['scheme'] === 'https') {
            return true;
        }

        return $parts['scheme'] === 'http'
            && app()->environment(['local', 'testing'])
            && in_array($parts['host'], ['127.0.0.1', 'localhost'], true);
    }
}
