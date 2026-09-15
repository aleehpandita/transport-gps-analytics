# Esquema de datos

Documento de referencia para todo el equipo. Cualquier cambio a este esquema debe discutirse antes de implementarse, porque backend, generador sintético y dashboard dependen de que coincida.

## Catálogo de zonas (`zones`)

Snapshot de referencia tomado del catálogo real de zonas operativas de Feraltar (ver [`zones.csv`](zones.csv)). **No está conectado a la base de datos de producción de Feraltar** — es una copia propia del proyecto, poblada mediante un seeder.

Columnas:

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | int | Igual al id de referencia del catálogo original, para trazabilidad |
| `name` | string | Nombre de la zona |
| `time_from_airport_raw` | string | Texto original tal cual venía en el catálogo (ej. "1 hour and 30 minutes") |
| `time_from_airport_minutes` | int | Tiempo estimado desde/hacia el aeropuerto, en minutos — parseado del texto original |
| `is_airport` | boolean | `true` únicamente en la zona que representa el aeropuerto de Cancún |
| `requires_ferry_transfer` | boolean | `true` para zonas donde el vehículo transfiere al pasajero a un muelle en vez de llegar directamente al destino (Cozumel, Isla Mujeres) |
| `is_active` | boolean | Heredado del catálogo original |

Notas:
- El aeropuerto es la zona `Cancun` — no se crea una zona nueva para representarlo, se marca `is_airport = true` sobre la existente.
- Para Cozumel e Isla Mujeres, el destino real de la telemetría GPS es el muelle de transferencia (Playa del Carmen / Puerto Juárez respectivamente), no la isla — el generador sintético y el trip builder deben tratarlo así.

## `trips`

Datos operativos observados — hechos, no derivaciones.

| Columna | Tipo | Notas |
|---|---|---|
| `vehicle_id` | FK | |
| `origin_zone_id` | FK → zones.id, nullable | No se asume que el aeropuerto sea siempre un extremo del viaje |
| `destination_zone_id` | FK → zones.id, nullable | |
| `started_at` | datetime | |
| `ended_at` | datetime | |
| `duration_seconds` | int | Target real, conocido solo al terminar el viaje |
| `distance_km` | float | |
| `average_speed` | float | **Descriptivo — no usar como input del modelo baseline** |
| `max_speed` | float | **Descriptivo — no usar como input del modelo baseline** |
| `stops_count` | int | **Descriptivo — no usar como input del modelo baseline** |
| `stopped_seconds` | int | **Descriptivo — no usar como input del modelo baseline** |
| `data_source` | enum('real','synthetic') | Obligatorio, siempre explícito |

## `trip_features` (capa derivada, recalculable)

Generada por un pipeline de feature engineering a partir de `trips` — nunca se guarda como "verdad operativa" en `trips`.

| Columna | Tipo | Notas |
|---|---|---|
| `trip_id` | FK → trips.id | |
| `hour_of_day` | int | |
| `day_of_week` | int | |
| `distance_km` | float | |
| `origin_zone_id` | FK → zones.id | |
| `destination_zone_id` | FK → zones.id | |
| `previous_trip_duration` | int | Duración del viaje anterior del mismo vehículo — sí disponible al iniciar el viaje actual |
| `historical_corridor_mean_duration` | int | Promedio histórico del corredor origen-destino a esa hora |

## Regla estricta de data leakage

El modelo baseline debe predecir la duración total del viaje **al momento de iniciarlo**. Por lo tanto, solo pueden usarse como input variables disponibles en ese instante.

**Features permitidas (input del modelo):**
- `distance_km`
- `hour_of_day`
- `day_of_week`
- `origin_zone_id`
- `destination_zone_id`
- `previous_trip_duration`
- `historical_corridor_mean_duration`

**Nunca usar como input** (se conocen solo después de que el viaje termina o ya sucedió):
- `average_speed`, `max_speed`, `stops_count`, `stopped_seconds` del viaje actual
- `ended_at`, `duration_seconds` / `actual_duration`
- cualquier feature que dependa de eventos futuros al viaje

Estas variables sí se almacenan en `trips` para análisis descriptivo, EDA, reportes, y para generar el target (`duration_seconds`) — solo no deben entrar como input del modelo baseline.

Si más adelante se implementa ETA dinámico (durante el viaje, no al iniciarlo), se trata como un problema de ML separado, con su propio conjunto de features.

## Baseline de referencia

`zones.time_from_airport_minutes` sirve como baseline de negocio ya validado por Feraltar, pero **solo aplica a viajes donde uno de los dos extremos es el aeropuerto**. Para viajes zona-a-zona no existe baseline de negocio — el modelo debe apoyarse en `distance_km`, `hour_of_day` e histórico del corredor.

## `TripBuilderService` (conceptual — a implementar en backend)

Responsable de transformar una secuencia temporal de `positions` en registros de `trips`. Debe usar las mismas reglas tanto para posiciones reales (Traccar) como sintéticas, para que ambos datasets sean comparables.

Umbrales iniciales propuestos (ajustables, deben ser configurables — no hardcodeados):

- **Inicio de viaje:** `ignition = true` y velocidad sostenida > 0 por más de 30 segundos.
- **Fin de viaje:** `ignition = false` sostenido por más de 5 minutos, o gap de reporte GPS mayor a 10 minutos.
- **Parada intermedia (no cuenta como fin de viaje):** velocidad = 0 por más de 2 minutos con `ignition = true`.

Estos umbrales se calibrarán con datos reales de la unidad piloto una vez que el backend esté sincronizando telemetría.
