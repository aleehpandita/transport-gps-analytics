<?php

use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('vehicles')->group(function () {
    Route::get('/', [VehicleController::class, 'index']);
    Route::get('/{vehicle}', [VehicleController::class, 'show']);
    Route::get('/{vehicle}/latest-position', [VehicleController::class, 'latestPosition']);
    Route::get('/{vehicle}/positions', [VehicleController::class, 'positions']);
    Route::get('/{vehicle}/trips', [VehicleController::class, 'trips']);
});