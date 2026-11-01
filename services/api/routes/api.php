<?php

declare(strict_types=1);

use App\Http\Controllers\ReaderAuthController;
use App\Http\Controllers\AdvertiserCampaignController;
use App\Http\Controllers\ReaderEmailVerificationController;
use App\Http\Controllers\ReaderPasswordResetController;
use App\Http\Controllers\ReaderProfileController;
use App\Http\Controllers\StaffInvitationAcceptanceController;
use App\Http\Controllers\StaffMfaEnrollmentController;
use App\Http\Controllers\StaffAuthController;
use App\Http\Controllers\StaffSupportTicketController;
use App\Http\Controllers\StaffEditorialDraftController;
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

        // Private editor drafts, revisioned and unpublishable at this stage.
        Route::get('/editorial/drafts', [StaffEditorialDraftController::class, 'index']);
        Route::post('/editorial/drafts', [StaffEditorialDraftController::class, 'store'])
            ->middleware('throttle:20,1');
        Route::get('/editorial/drafts/{draft}', [StaffEditorialDraftController::class, 'show'])
            ->whereNumber('draft');
        Route::patch('/editorial/drafts/{draft}', [StaffEditorialDraftController::class, 'update'])
            ->whereNumber('draft')->middleware('throttle:40,1');
        Route::post('/editorial/drafts/{draft}/submit', [StaffEditorialDraftController::class, 'submit'])
            ->whereNumber('draft')->middleware('throttle:10,1');

        // No public ticket discovery: SQL assignment scope and Gate both enforce access.
        Route::get('/support/tickets/{ticket}', [StaffSupportTicketController::class, 'show'])
            ->whereNumber('ticket');
        Route::post('/support/tickets/{ticket}/replies', [StaffSupportTicketController::class, 'reply'])
            ->whereNumber('ticket')->middleware('throttle:20,1');
        Route::post('/support/tickets/{ticket}/escalate', [StaffSupportTicketController::class, 'escalate'])
            ->whereNumber('ticket')->middleware('throttle:6,1');
    });
});

// Advertiser principals are isolated from staff and reader identities.
// No public account provisioning or campaign publication is exposed.
Route::middleware(['auth:sanctum', 'advertiser.session'])
    ->prefix('advertiser')->group(function (): void {
        Route::get('/organizations', [AdvertiserCampaignController::class, 'organizations']);
        Route::get('/organizations/{organization}/campaigns', [AdvertiserCampaignController::class, 'index'])
            ->whereNumber('organization');
        Route::post('/organizations/{organization}/campaigns', [AdvertiserCampaignController::class, 'store'])
            ->whereNumber('organization')->middleware('throttle:20,1');
        Route::get('/organizations/{organization}/campaigns/{campaign}', [AdvertiserCampaignController::class, 'show'])
            ->whereNumber('organization')->whereNumber('campaign');
        Route::patch('/organizations/{organization}/campaigns/{campaign}', [AdvertiserCampaignController::class, 'update'])
            ->whereNumber('organization')->whereNumber('campaign')->middleware('throttle:40,1');
    });
