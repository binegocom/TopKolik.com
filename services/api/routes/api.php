<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/status', fn () => response()->json(['service' => 'topkolik-api', 'status' => 'operational']))
    ->name('api.status');
