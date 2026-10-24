<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\LiveVehicleController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScheduledServiceController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

// Lo único público: iniciar sesión
Route::post('/login', [AuthController::class, 'login']);

// Todo lo demás requiere sesión. Cada controlador filtra por las unidades del usuario.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Antes del grupo de /vehicles/{vehicle}: si no, "live" se tomaría como el id de una unidad
    Route::get('/vehicles/live', [LiveVehicleController::class, 'index']);

    Route::prefix('vehicles')->group(function () {
        Route::get('/', [VehicleController::class, 'index']);
        Route::get('/{vehicle}', [VehicleController::class, 'show']);
        Route::get('/{vehicle}/latest-position', [VehicleController::class, 'latestPosition']);
        Route::get('/{vehicle}/positions', [VehicleController::class, 'positions']);
        Route::get('/{vehicle}/trips', [VehicleController::class, 'trips']);
        Route::get('/{vehicle}/route', [VehicleController::class, 'route']);
    });

    Route::get('/trips', [TripController::class, 'index']);

    Route::prefix('reports')->group(function () {
        Route::get('/daily', [ReportController::class, 'daily']);
        Route::get('/trips', [ReportController::class, 'trips']);
        Route::get('/stops', [ReportController::class, 'stops']);
    });

    Route::get('/events', [EventController::class, 'index']);

    Route::get('/zones', [ZoneController::class, 'index']);

    Route::prefix('scheduled-services')->group(function () {
        Route::get('/', [ScheduledServiceController::class, 'index']);
        Route::post('/', [ScheduledServiceController::class, 'store']);
    });
});