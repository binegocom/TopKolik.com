<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
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
        RateLimiter::for('reader-register', fn (Request $request) => Limit::perMinute(5)
            ->by('register:'.($request->ip() ?? 'unknown')));

        RateLimiter::for('reader-login', fn (Request $request) => Limit::perMinute(10)
            ->by('login:'.($request->ip() ?? 'unknown').':'.hash('sha256', mb_strtolower((string) $request->input('email', '')))));

        RateLimiter::for('reader-verification', fn (Request $request) => Limit::perMinute(4)
            ->by('verify:'.($request->ip() ?? 'unknown').':'.hash('sha256', mb_strtolower((string) $request->input('email', '')))));
    }
}
