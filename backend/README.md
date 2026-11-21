# Backend (Laravel 12)

API y procesos programados de la plataforma. Consume posiciones de Traccar por su API, arma viajes, detecta eventos, revisa alertas y genera reportes.

## Requisitos

- PHP 8.2 o superior, Composer
- SQLite (modo WAL)
- Traccar accesible por API (variables `TRACCAR_*` en `.env`)

## Arranque local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan schedule:work   # corre los procesos programados
```

## Comandos principales

| Comando | Qué hace |
|---|---|
| `traccar:sync-devices` | Sincroniza los dispositivos de Traccar |
| `traccar:sync-positions` | Trae posiciones nuevas y descarta duplicados (cada 2 min) |
| `trips:build` | Arma viajes con origen, destino y zonas (cada 10 min) |
| `events:detect` | Detecta unidades sin señal y paradas largas (cada minuto) |
| `alerts:check` | Revisa reglas de alertas por empresa y avisa por correo (cada minuto) |
| `geofences:visits` | Registra entradas y salidas de geocercas (cada 5 min) |
| `orders:import` | Importa órdenes de servicio para ligarlas con los viajes |
| `places:import-osm`, `signals:import-osm` | Importan hoteles y semáforos de OpenStreetMap |
| `users:create` | Crea un usuario del panel |

La programación está en `routes/console.php`. Revisa `php artisan list` para los nombres exactos y opciones.
