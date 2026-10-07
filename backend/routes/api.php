<?php

use App\Http\Controllers\Api\ScheduledServiceController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

Route::prefix('vehicles')->group(function () {
    Route::get('/', [VehicleController::class, 'index']);
    Route::get('/{vehicle}', [VehicleController::class, 'show']);
    Route::get('/{vehicle}/latest-position', [VehicleController::class, 'latestPosition']);
    Route::get('/{vehicle}/positions', [VehicleController::class, 'positions']);
    Route::get('/{vehicle}/trips', [VehicleController::class, 'trips']);
});

Route::get('/trips', [TripController::class, 'index']);

Route::get('/zones', [ZoneController::class, 'index']);

Route::prefix('scheduled-services')->group(function () {
    Route::get('/', [ScheduledServiceController::class, 'index']);
    Route::post('/', [ScheduledServiceController::class, 'store']);
});