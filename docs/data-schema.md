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
| `region` | string | Default `'Cancun-Riviera Maya'`. Permite distinguir zonas de distintas operaciones/regiones si el proyecto se expande a otras plazas (ej. Los Cabos) — decisión tomada para no acoplar el esquema a una sola región. |
| `latitude` | float, nullable | Punto de referencia geográfico de la zona. Para zonas tipo "corredor" (la mayoría, sobre la carretera costera), es el punto de frontera donde empieza esa zona. Pendiente de llenar con referencias reales aportadas por alguien que conoce las zonas de operación (no coordenadas aproximadas/adivinadas). |
| `longitude` | float, nullable | Igual que `latitude`. |
| `geofence_radius_km` | float, nullable | Solo se llena para zonas tipo "área" (ej. Cancún, que no es un punto en una carretera sino toda una zona con aeropuerto + hotelera). Si tiene valor, el matching de zona usa "¿está a menos de X km del centro?"; si es `null`, usa "¿cuál es el punto de frontera más cercano?". |

### Matching de zonas (asignar `origin_zone_id`/`destination_zone_id` a un trip)

Dado un par de coordenadas (origen o destino de un `trip`), se determina la zona correspondiente así:

1. Si existe una zona con `geofence_radius_km` no nulo y la coordenada cae dentro de ese radio (Haversine), se asigna esa zona (caso Cancún).
2. Si no, se calcula la distancia Haversine contra el punto (`latitude`/`longitude`) de cada zona restante, y se asigna la más cercana.

No se usan polígonos ni geocercas complejas — decisión consciente para mantener el MVP simple; suficiente porque la mayoría de las zonas están alineadas sobre una sola vía costera, no dispersas en un área 2D.

Notas:
- El aeropuerto es la zona `Cancun` (id 4) — no se crea una zona nueva para representarlo, se marca `is_airport = true` sobre la existente.
- Para Cozumel e Isla Mujeres, el destino real de la telemetría GPS es el muelle de transferencia (Playa del Carmen / Puerto Juárez respectivamente), no la isla — el generador sintético y el trip builder deben tratarlo así.

## Resolución de identificadores (`vehicles`)

Decisión cerrada — no usar strings de negocio inventados (`FMC920_01`, `TIGUAN_01`). Se usan los identificadores reales de Traccar más un identificador propio:

| Columna | Origen | Uso |
|---|---|---|
| `vehicle_id` (PK) | Interno, autoincremental | Usado por `trips` y `positions` para relacionarse. Nunca cambia aunque el hardware GPS cambie. |
| `traccar_device_id` | `id` de Traccar (ej. `1`) | Se usa para consultar `/api/positions?deviceId=...` |
| `imei` | `uniqueId` de Traccar (ej. `"863238071763025"`) | Dato de negocio, identifica el hardware físico |

Un GPS puede moverse entre vehículos; por eso `positions`/`trips` nunca usan `traccar_device_id` directamente como llave de relación, solo `vehicle_id`.

## `positions`

Telemetría cruda, viene de Traccar (o del generador sintético, con el mismo esquema).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | PK | |
| `vehicle_id` | FK → vehicles.id | |
| `traccar_position_id` | int, nullable | Nulo en registros sintéticos |
| `latitude`, `longitude` | float | Grados decimales |
| `altitude` | float | |
| `speed` | float | **km/h** — Traccar regresa nudos (knots) en su API; se convierte al ingerir, nunca se guarda el valor crudo sin convertir |
| `course` | float | |
| `accuracy` | float, nullable | |
| `ignition` | boolean, nullable | Traccar lo anida dentro de `attributes` — se extrae y promueve a esta columna en la ingesta |
| `motion` | boolean, nullable | Igual que `ignition`, viene dentro de `attributes` en el payload real |
| `valid` | boolean | Confiabilidad del fix de GPS según Traccar — filtrar antes de usar en trip builder o modelo de ML |
| `device_time` | datetime UTC | **Timestamp canónico** — momento real de captura del GPS (`deviceTime` de Traccar) |
| `server_time` | datetime UTC | Solo referencia de latencia de red (`serverTime` de Traccar), no se usa para lógica de negocio |
| `attributes` | JSON | Captura completa del payload crudo de Traccar sin filtrar — evita perder campos no anticipados (`odometer`, `satellites`, etc.) hasta confirmar qué expone realmente el FMC920 |
| `data_source` | enum('real','synthetic') | |

**Confirmado con payload real (2026-09-23):**
- El payload real trae además: `odometer`, `totalDistance`, `power`, `battery`, `operator`, `rssi`, `priority`, `sat`, `event`, `hours`, e IDs de I/O específicos de Teltonika (`io200`, `io69`, `io68`) — todos se quedan dentro de `attributes`, no se promueven a columnas.
- **Pendiente:** `odometer` y `totalDistance` traen valores muy distintos entre sí en el primer payload (vehículo detenido) — no asumir que están en la misma unidad hasta verificar con un tramo en movimiento real.
- Con el vehículo estacionado, Traccar reporta una posición aproximadamente cada hora (heartbeat), con `valid: false` y `accuracy: 0`.
- Todo el sistema opera internamente en **UTC** — la hora de Cancún (UTC-5) se obtiene restando 5 horas; la conversión a hora local se hace únicamente en la capa de presentación (dashboard/reportes), nunca en lo almacenado.

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
| `previous_trip_duration` | int | **Definición cerrada:** duración del viaje inmediatamente anterior del mismo `vehicle_id`, ordenado por `actual_start`. No por conductor ni por corredor — esa granularidad la cubre `historical_corridor_mean_duration` por separado. |
| `historical_corridor_mean_duration` | int | Promedio histórico del corredor origen-destino a esa hora |

`trips.origin` / `trips.destination` (texto libre, dato operativo crudo del sistema de reservaciones) y `trip_features.origin_zone_id` / `destination_zone_id` (resuelto contra el catálogo `zones`, calculado en feature engineering) coexisten sin conflicto — no son la misma cosa ni se reemplazan entre sí.

## Regla estricta de data leakage

El modelo baseline debe predecir la duración total del viaje **al momento de iniciarlo**.

**Features permitidas (input del modelo):** `distance_km`, `hour_of_day`, `day_of_week`, `origin_zone_id`, `destination_zone_id`, `previous_trip_duration`, `historical_corridor_mean_duration`.

**Nunca usar como input:** `average_speed`, `max_speed`, `stops_count`, `stopped_seconds` del viaje actual, `ended_at`, `duration_seconds`, o cualquier feature que dependa de eventos futuros al viaje. Estas sí se almacenan para EDA y para generar el target.

## Baseline de referencia

`zones.time_from_airport_minutes` sirve como baseline de negocio, pero **solo aplica a viajes donde uno de los dos extremos es el aeropuerto**. Para viajes zona-a-zona no existe baseline de negocio.

## Reglas globales

| Regla | Decisión |
|---|---|
| Formato de fechas | ISO 8601 |
| Zona horaria | UTC interno (conversión a hora Cancún solo en presentación) |
| Velocidad | km/h (convertido desde knots de Traccar al ingerir) |
| Coordenadas | Grados decimales |
| Nulos | `null`, nunca cadena vacía |
| IDs | Numéricos internos (ver "Resolución de identificadores") |
| Booleanos | `true` / `false` / `null` |
| Formato de entrenamiento | CSV/Parquet |
| Target | `trip_duration_seconds` |
| Datos sintéticos | Mismo esquema que los reales, `data_source = 'synthetic'` |
| Filtrado por origen | Todo dataset de experimentación debe permitir filtrar explícitamente por `data_source` |
| Evaluación final | Siempre con datos reales, nunca sintéticos |

## `TripBuilderService` (conceptual — a implementar en backend)

Responsable de transformar una secuencia temporal de `positions` en registros de `trips`. Debe usar las mismas reglas tanto para posiciones reales como sintéticas.

Umbrales iniciales (ajustables, deben ser configurables — no hardcodeados):

- **Inicio de viaje:** `ignition = true` y velocidad sostenida > 0 por más de 30 segundos.
- **Fin de viaje:** `speed = 0` sostenido y `ignition` transiciona de `true` a `false`, con un margen de confirmación amplio (ver hallazgo de 2026-09-28 abajo — un margen corto de 1-2 min resultó insuficiente en la práctica).
- **Parada intermedia (no cuenta como fin de viaje):** velocidad = 0 con `ignition = true` sostenido durante toda la parada.

**Calibrado con el primer viaje real completo (2026-09-25, 22:07-22:09 UTC — fin de viaje real):**
- `ignition` resultó ser la señal más rápida y confiable para fin de viaje: pasó de `true` a `false` a los ~17 segundos de que `speed` llegó a 0, sin rebotar de vuelta a `true`.
- `motion` **no es una señal instantánea** — tiene un retraso variable (~60-90s en este caso) respecto a `speed`/`ignition` (Traccar aplica su propio debounce interno). Se usa como señal secundaria/de respaldo, no primaria.
- Después de `motion = false`, el heartbeat vuelve al patrón de ~1 posición/hora observado con el vehículo en reposo.

**Calibrado con una parada intermedia real (2026-09-28, ~3 min 33 s en un cajero automático, con el vehículo circulando antes y después):**
- `ignition` se mantuvo en `true` durante toda la parada, sin apagarse en ningún momento — esta es la señal clave que diferencia una parada intermedia de un fin de viaje real: en el fin de viaje calibrado previamente, `ignition` pasó a `false` a los ~17s y nunca regresó; aquí, con el motor sin apagar, `ignition` nunca varió.
- `motion` sí reaccionó esta vez con solo ~12s de retraso (mucho más rápido que los ~60-90s observados en el caso de fin de viaje) — confirma que el retraso de `motion` es variable, no un valor fijo, y refuerza por qué no se usa como señal primaria en ningún escenario.
- **Pendiente de confirmar:** si una transición de `ignition = false` de muy corta duración (segundos) puede corresponder también a una parada intermedia real (motor apagado brevemente, ej. un mandado corto) en vez de fin de viaje — de ser así, el margen de confirmación de 1-2 min antes de declarar fin de viaje es aún más necesario de lo que se pensaba. Caso detectado el 2026-09-28 a las 16:38:12 UTC, en observación.

**Hallazgo crítico — corrección del umbral de fin de viaje (2026-09-28, parada de ~9 min con motor apagado):**

El caso pendiente de arriba se resolvió, y corrige una asunción previa: `ignition` se apagó a los 8 segundos de detenerse (16:38:20) y permaneció apagado durante **casi 8 minutos** (hasta 16:46:15), tras lo cual el motor se reencendió y el viaje continuó normalmente. Esto invalida el margen de confirmación de "1-2 minutos" que se había propuesto — con ese umbral, el trip builder habría cortado este viaje en dos de forma incorrecta.

Con los tres casos reales acumulados hasta ahora:

| Caso | `ignition` apagado por | ¿Fin de viaje real? |
|---|---|---|
| Cajero (2026-09-28) | Nunca se apagó | No — parada intermedia |
| Toks/gym (2026-09-28) | ~8 minutos | No — parada intermedia |
| Fin de viaje (2026-09-25) | Más de 1.5 horas (nunca regresó) | Sí |

**El umbral real de confirmación está en algún punto entre 8 minutos y 1.5 horas — todavía no acotado con precisión.** Punto de partida conservador mientras se recopilan más casos: **15-20 minutos** de `ignition = false` sostenido sin retomar movimiento, en vez de los 1-2 minutos originalmente propuestos. Debe seguir siendo configurable, no hardcodeado, y se ajustará con más datos reales de la unidad piloto.
