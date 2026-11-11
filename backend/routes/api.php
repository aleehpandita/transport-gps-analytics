<?php

use App\Http\Controllers\Api\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\Admin\VehicleAdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyUserController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\LiveVehicleController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScheduledServiceController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\TripReviewController;
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

    Route::get('/operations', [OperationController::class, 'index']);
    Route::post('/trip-reviews', [TripReviewController::class, 'store']);

    // Solo administradores (lo revisa el controlador)
    Route::post('/places', [PlaceController::class, 'store']);

    Route::get('/zones', [ZoneController::class, 'index']);

    Route::prefix('scheduled-services')->group(function () {
        Route::get('/', [ScheduledServiceController::class, 'index']);
        Route::post('/', [ScheduledServiceController::class, 'store']);
    });

    // El dueño de cada empresa administra a sus usuarios
    Route::middleware('company.owner')->prefix('company')->group(function () {
        Route::get('/users', [CompanyUserController::class, 'index']);
        Route::post('/users', [CompanyUserController::class, 'store']);
        Route::patch('/users/{user}', [CompanyUserController::class, 'update']);
        Route::post('/users/{user}/reset-password', [CompanyUserController::class, 'resetPassword']);
        Route::delete('/users/{user}', [CompanyUserController::class, 'destroy']);
    });

    // Panel de EkMira: empresas, unidades y usuarios de todos los clientes
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/companies', [AdminCompanyController::class, 'index']);
        Route::post('/companies', [AdminCompanyController::class, 'store']);
        Route::get('/companies/{company}', [AdminCompanyController::class, 'show']);
        Route::patch('/companies/{company}', [AdminCompanyController::class, 'update']);
        Route::post('/companies/{company}/vehicles', [VehicleAdminController::class, 'store']);
        Route::post('/companies/{company}/users', [UserAdminController::class, 'store']);
        Route::post('/view-as', [AdminCompanyController::class, 'viewAs']);

        Route::get('/vehicles', [VehicleAdminController::class, 'index']);
        Route::patch('/vehicles/{vehicle}', [VehicleAdminController::class, 'update']);

        Route::patch('/users/{user}', [UserAdminController::class, 'update']);
        Route::post('/users/{user}/reset-password', [UserAdminController::class, 'resetPassword']);
        Route::delete('/users/{user}', [UserAdminController::class, 'destroy']);
    });
});
