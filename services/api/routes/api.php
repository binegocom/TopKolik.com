<?php

declare(strict_types=1);

use App\Http\Controllers\ReaderAuthController;
use App\Http\Controllers\ReaderEmailVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/status', fn () => response()->json(['service' => 'topkolik-api', 'status' => 'operational']))
    ->name('api.status');

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [ReaderAuthController::class, 'register'])->middleware('throttle:reader-register');
    Route::post('/login', [ReaderAuthController::class, 'login'])->middleware('throttle:reader-login');
    Route::post('/verification/resend', [ReaderAuthController::class, 'resendVerification'])
        ->middleware('throttle:reader-verification');

    // Signed proof-of-email link does not grant a session or a mobile token.
    Route::get('/email/verify/{id}/{hash}', ReaderEmailVerificationController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware(['auth:sanctum', 'ability:reader:read'])->group(function (): void {
        Route::get('/me', [ReaderAuthController::class, 'me']);
        Route::post('/logout', [ReaderAuthController::class, 'logout']);
    });
});
