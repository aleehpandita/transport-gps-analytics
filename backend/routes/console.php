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
| - Eventos cada minuto: solo lee las posiciones nuevas de cada unidad, así que
|   es barato; cada minuto también revisa lo que depende del reloj (sin señal,
|   parada larga sin posiciones nuevas). Umbrales en config/alerts.php.
| - Alertas de los clientes cada minuto: las reglas que cada empresa configura en
|   el panel (velocidad, geocercas, parada larga, sin señal, fuera de horario).
|   Igual que los eventos, solo lee lo nuevo. Manda los correos en la misma corrida.
*/

// 10 h: cubre el retraso más largo medido (8.4 h). Los huecos más grandes (servidor o
// cron caídos) los rellena el propio comando desde la última posición guardada.
Schedule::command('traccar:sync-positions --hours=10')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('trips:build')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('events:detect')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('alerts:check')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
