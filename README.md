# Predicción del tiempo de traslado con datos GPS para Feraltar

Proyecto Integrador (TC5035), Equipo 56. Maestría en Inteligencia Artificial Aplicada, Tecnológico de Monterrey.
Aplicado a [Feraltar](https://feraltar.com), empresa de transporte turístico y traslados de aeropuerto en Cancún y la Riviera Maya.

## Objetivo

Estimar cuánto va a tardar un traslado (ETA) para que la operación pueda empatar servicios con datos y no solo por experiencia. Para eso construimos una plataforma de rastreo propia y, sobre sus datos, un modelo de regresión.

## Resultados

| Modelo | MAE en prueba (min) |
|---|---|
| Regla de distancia entre velocidad | 11.3 |
| Regresión lineal con zonas y hora | 7.9 |
| Árbol de decisión | 3.9 |
| Red neuronal (MLP) | 3.5 |
| **Gradient Boosting (modelo final)** | **3.4** |

- 95.7 % de los viajes queda dentro de ±10 min (meta: 90 %).
- 69.6 % menos error que la regla de distancia entre velocidad.
- Predice un viaje en unos 31 ms, pesa menos de 5 MB y se reentrena en menos de un minuto.
- Partición temporal 80/20: entrenamiento con las primeras semanas y prueba con los últimos días.

**Limitación principal:** de los 436,807 viajes, casi todos son sintéticos, generados sobre los corredores reales de la operación. Por eso la recomendación es un despliegue en sombra para validar el modelo con viajes reales antes de usarlo en la planeación.

## Arquitectura

```
Rastreadores Teltonika FMC920
        | TCP
        v
Traccar (recepción de posiciones)
        | API interna (127.0.0.1)
        v
Backend Laravel 12 ---> SQLite (WAL) ---> respaldo nocturno en Amazon S3
        |                      |
        | HTTPS                | viajes reales (reentrenamiento, propuesto)
        v                      v
Panel React              Servicio de ETA en Python (propuesto)
```

Todo corre en una instancia AWS EC2 t3.small (us-east-1). Traccar se usa solo como fuente de telemetría: el backend lo consume por su API y nunca entra directo a su base de datos.

Procesos programados del backend (`backend/routes/console.php`):

| Comando | Frecuencia | Qué hace |
|---|---|---|
| `traccar:sync-positions` | cada 2 min | Trae posiciones nuevas de Traccar y descarta duplicados |
| `trips:build` | cada 10 min | Arma los viajes con su origen, destino y zonas |
| `events:detect` | cada minuto | Detecta eventos: sin señal, paradas largas |
| `alerts:check` | cada minuto | Revisa las reglas de alertas por empresa y avisa por correo |
| `geofences:visits` | cada 5 min y diario a las 04:20 | Registra entradas y salidas de geocercas |

## Estructura del repositorio

```
backend/     Laravel 12: integración con Traccar, viajes, eventos, alertas, geocercas, reportes y API
frontend/    Panel en React + TypeScript + Vite: mapa en vivo, viajes, alertas, geocercas y reportes
notebooks/   Avances del proyecto (EDA, características, baseline, modelos, modelo final)
data/        raw (exportación real), synthetic (viajes sintéticos), processed (datos listos para modelar)
docs/        Esquema de datos, zonas, zonificación y bitácora del piloto FMC920
ops/         Respaldo nocturno a S3
deploy.sh    Despliegue en el servidor
```

## Notebooks

| Notebook | Contenido |
|---|---|
| `SyntheticGeneration.ipynb` | Generador de viajes sintéticos sobre los corredores reales |
| `BaselineCalculation.ipynb` | Matriz de tiempos base por zona |
| `Avance1.56.ipynb` | Análisis exploratorio de datos |
| `Avance2_56.ipynb` | Ingeniería de características |
| `Avance3_56.ipynb` | Modelo baseline |
| `Avance4_56.ipynb` | Modelos alternativos y ajuste de hiperparámetros |
| `Avance5_56.ipynb` | Ensambles (Voting, Stacking, Gradient Boosting) y modelo final |

## Datos: reales y sintéticos

Cada viaje lleva el campo `data_source` (`real` o `synthetic`), así que las dos fuentes siempre se distinguen. Los sintéticos nunca se presentan como reales. El esquema está en [`docs/data-schema.md`](docs/data-schema.md).

## Estado actual

- Plataforma en operación: rastreadores instalados en unidades piloto, servidor, backend, panel y respaldos funcionando.
- Retraso de posiciones resuelto desde el 22 de octubre de 2026 (ver [`docs/piloto-fmc920.md`](docs/piloto-fmc920.md), sección 4.9).
- Modelo final entrenado y evaluado. Pendiente: integrarlo como servicio y arrancar el despliegue en sombra.

## Siguientes pasos

1. Despliegue en sombra durante 8 semanas: el ETA se calcula y se registra, pero la operación se sigue armando como hoy.
2. Reentrenar con los viajes reales que registra la plataforma.
3. Agregar tráfico (Google Routes API) y clima (Open-Meteo).
4. Migrar la base de Traccar de H2 a PostgreSQL antes de sumar más unidades.

## Equipo

| Persona | Responsabilidad principal |
|---|---|
| Alejandra Guadalupe Larrañaga Altamirano | Plataforma (Traccar, backend, panel, infraestructura), implementación y operación |
| Andrea Kimberly Fray Tamayo | Datos, metodología y modelos |
| Mario Alberto Guillén De La Torre | Resultados, modelos y evaluación de negocio |

## Documentación

- [`docs/data-schema.md`](docs/data-schema.md): esquema de `trips` y `trip_features`, catálogo de zonas y regla de data leakage.
- [`docs/zonificacion-maestra.md`](docs/zonificacion-maestra.md): zonas operativas.
- [`docs/piloto-fmc920.md`](docs/piloto-fmc920.md): configuración y hallazgos del piloto GPS.

## Principios

- Nada de credenciales, IPs ni tokens en el código: todo va en variables de entorno.
- Ninguna característica del modelo usa información que no exista al iniciar el viaje.
- Datos reales y sintéticos siempre separables con `data_source`.
