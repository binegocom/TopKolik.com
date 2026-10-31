<?php

declare(strict_types=1);

use App\Http\Controllers\ReaderAuthController;
use App\Http\Controllers\ReaderEmailVerificationController;
use App\Http\Controllers\ReaderPasswordResetController;
use App\Http\Controllers\ReaderProfileController;
use App\Http\Controllers\StaffInvitationAcceptanceController;
use App\Http\Controllers\StaffMfaEnrollmentController;
use App\Http\Controllers\StaffAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/status', fn () => response()->json(['service' => 'topkolik-api', 'status' => 'operational']))
    ->name('api.status');

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [ReaderAuthController::class, 'register'])->middleware('throttle:reader-register');
    Route::post('/login', [ReaderAuthController::class, 'login'])->middleware('throttle:reader-login');
    Route::post('/password/forgot', [ReaderPasswordResetController::class, 'forgot'])
        ->middleware('throttle:reader-forgot-password');
    Route::post('/password/reset', [ReaderPasswordResetController::class, 'reset'])
        ->middleware('throttle:reader-reset-password');
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


// Reader-only account resources: never accept a client-selected user ID.
Route::middleware('auth:sanctum')->prefix('me')->group(function (): void {
    Route::middleware('ability:reader:read')->group(function (): void {
        Route::get('/', [ReaderProfileController::class, 'show']);
        Route::get('/preferences', [ReaderProfileController::class, 'preferences']);
        Route::get('/favorites', [ReaderProfileController::class, 'favorites']);
    });

    Route::middleware('ability:reader:write')->group(function (): void {
        Route::patch('/', [ReaderProfileController::class, 'update']);
        Route::patch('/preferences', [ReaderProfileController::class, 'updatePreferences']);
        Route::put('/favorites', [ReaderProfileController::class, 'replaceFavorites']);
    });
});

// Public acceptance only; never expose invitation issuance, staff login or role mutation.
Route::post('/staff/invitations/accept', StaffInvitationAcceptanceController::class)
    ->middleware('throttle:staff-invite-accept');


Route::prefix('staff')->group(function (): void {
    Route::post('/mfa/setup', [StaffMfaEnrollmentController::class, 'setup'])
        ->middleware('throttle:staff-mfa-setup');
    Route::post('/mfa/confirm', [StaffMfaEnrollmentController::class, 'confirm'])
        ->middleware('throttle:staff-mfa-confirm');
    Route::post('/auth/login', [StaffAuthController::class, 'login'])
        ->middleware('throttle:staff-login');

    Route::middleware(['auth:sanctum', 'staff.session'])->group(function (): void {
        Route::get('/auth/me', [StaffAuthController::class, 'me']);
        Route::post('/auth/logout', [StaffAuthController::class, 'logout']);
        Route::post('/auth/step-up', [StaffAuthController::class, 'stepUp'])
            ->middleware('throttle:staff-step-up');
    });
});
