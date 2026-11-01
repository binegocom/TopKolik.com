<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use App\Authorization\StaffAuthorizationPolicy;
use App\Authorization\StaffResourceContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Gate::define('staff-create-draft', function (
            Authenticatable $principal,
            PersonalAccessToken $token,
        ): bool {
            return app(StaffAuthorizationPolicy::class)->canCreateDraft($principal, $token);
        });

        Gate::define('staff-resource', function (
            Authenticatable $principal,
            string $action,
            StaffResourceContext $resource,
            PersonalAccessToken $token,
        ): bool {
            return app(StaffAuthorizationPolicy::class)->allows($principal, $action, $resource, $token);
        });

        Gate::define('staff-audit-self', function (
            Authenticatable $principal,
            PersonalAccessToken $token,
        ): bool {
            return app(StaffAuthorizationPolicy::class)->canViewOwnAudit($principal, $token);
        });

        Gate::define('staff-ads-assignments-self', function (
            Authenticatable $principal,
            PersonalAccessToken $token,
        ): bool {
            return app(StaffAuthorizationPolicy::class)->canListOwnAdvertiserAssignments($principal, $token);
        });

        RateLimiter::for('reader-register', fn (Request $request) => Limit::perMinute(5)
            ->by('register:'.($request->ip() ?? 'unknown')));

        // Separate dimensions prevent credential stuffing against one account
        // from many IPs and spraying many accounts from a single IP.
        RateLimiter::for('reader-login', function (Request $request): array {
            $rawEmail = $request->input('email', '');
            $email = is_string($rawEmail) ? mb_strtolower(trim($rawEmail)) : '';

            return [
                Limit::perMinute(20)->by('reader-login-ip:'.($request->ip() ?? 'unknown')),
                Limit::perHour(12)->by('reader-login-account:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('reader-privacy-sensitive', function (Request $request): array {
            $principal = $request->user();
            $identity = $principal
                ? hash('sha256', get_class($principal).':'.$principal->getAuthIdentifier())
                : 'anonymous';

            return [
                Limit::perMinute(10)->by('privacy-ip:'.($request->ip() ?? 'unknown')),
                Limit::perHour(6)->by('privacy-account:'.$identity),
            ];
        });

        RateLimiter::for('reader-verification', fn (Request $request) => Limit::perMinute(4)
            ->by('verify:'.($request->ip() ?? 'unknown').':'.hash('sha256', mb_strtolower((string) $request->input('email', '')))));


        RateLimiter::for('reader-forgot-password', function (Request $request): array {
            $rawEmail = $request->input('email', '');
            $normalized = is_string($rawEmail) ? mb_strtolower(trim($rawEmail)) : '';

            return [
                Limit::perMinute(8)->by('forgot-ip:'.($request->ip() ?? 'unknown')),
                Limit::perHour(3)->by('forgot-account:'.hash('sha256', $normalized)),
            ];
        });

        RateLimiter::for('reader-reset-password', function (Request $request): array {
            $rawEmail = $request->input('email', '');
            $normalized = is_string($rawEmail) ? mb_strtolower(trim($rawEmail)) : '';

            return [
                Limit::perMinute(10)->by('reset-ip:'.($request->ip() ?? 'unknown')),
                Limit::perHour(5)->by('reset-account:'.hash('sha256', $normalized)),
            ];
        });

        RateLimiter::for('staff-invite-accept', function (Request $request): array {
            $inviteId = $request->input('invitation', '');
            $key = is_string($inviteId) ? hash('sha256', $inviteId) : 'invalid';

            return [
                Limit::perMinute(6)->by('staff-accept-ip:'.($request->ip() ?? 'unknown')),
                Limit::perMinute(4)->by('staff-accept-invite:'.$key),
            ];
        });

        RateLimiter::for('staff-mfa-setup', function (Request $request): array {
            $token = $request->input('setup_token', '');

            return [
                Limit::perMinute(8)->by('staff-mfa-setup-ip:'.($request->ip() ?? 'unknown')),
                Limit::perMinute(3)->by('staff-mfa-setup-token:'.hash('sha256', is_string($token) ? $token : '')),
            ];
        });

        RateLimiter::for('staff-mfa-confirm', function (Request $request): array {
            $token = $request->input('setup_token', '');

            return [
                Limit::perMinute(8)->by('staff-mfa-confirm-ip:'.($request->ip() ?? 'unknown')),
                Limit::perMinute(5)->by('staff-mfa-confirm-token:'.hash('sha256', is_string($token) ? $token : '')),
            ];
        });

        RateLimiter::for('staff-login', function (Request $request): array {
            $email = $request->input('email', '');
            $normalized = is_string($email) ? mb_strtolower(trim($email)) : '';

            return [
                Limit::perMinute(15)->by('staff-login-ip:'.($request->ip() ?? 'unknown')),
                Limit::perHour(10)->by('staff-login-account:'.hash('sha256', $normalized)),
            ];
        });

        RateLimiter::for('staff-step-up', function (Request $request): array {
            return [
                Limit::perMinute(8)->by('staff-step-up-ip:'.($request->ip() ?? 'unknown')),
                Limit::perHour(6)->by('staff-step-up-user:'.($request->user()?->getAuthIdentifier() ?? 'anonymous')),
            ];
        });

        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            $base = Config::get('auth.reader_password_reset_url');

            if (! is_string($base) || $base === '') {
                throw new \LogicException('Password reset URL not configured');
            }

            return $base.'?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986);
        });
    }
}
