<?php

declare(strict_types=1);

use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/ready', ReadinessController::class)->name('ready');
