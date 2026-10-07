<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Pipeline de telemetría
|--------------------------------------------------------------------------
| Detalle de las frecuencias en docs/piloto-fmc920.md.
|
| - Sync cada 2 minutos: el FMC920 envía cada 120 s (Send Period), así que
|   correr más seguido no trae posiciones nuevas.
| - Trip Builder cada 10 minutos, sobre su ventana por defecto de 48 h. Como
|   closeTrip() actualiza los viajes existentes, un viaje en curso o con
|   posiciones por llegar se corrige solo en las corridas siguientes.
| - No se programa trips:assign-zones: closeTrip() ya resuelve las zonas al
|   crear o actualizar cada viaje.
*/

Schedule::command('traccar:sync-positions')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('trips:build')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));