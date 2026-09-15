# Sistema Inteligente para la Optimización de Operaciones de Transporte mediante Inteligencia Artificial aplicada al Análisis Predictivo de Datos GPS

Proyecto Integrador — Maestría en Inteligencia Artificial, Tecnológico de Monterrey.
Aplicado a [Feraltar](https://feraltar.com), plataforma de transporte turístico y traslados de aeropuerto en Cancún.

## Objetivo

Transformar telemetría GPS (dispositivos Teltonika FMC920 vía Traccar) en información predictiva para la operación:

- **Objetivo principal (ML):** predicción de ETA / duración de trayectos (modelo supervisado de regresión).
- **Objetivos secundarios:** detección de retrasos (duración real vs. predicha), detección de anomalías operativas, análisis de patrones de movilidad.
- **Producto:** dashboard con visualización en tiempo real, alertas e indicadores para la operación de Feraltar.

## Arquitectura

```
Teltonika FMC920
      ↓
   Traccar (recepción y normalización de telemetría GPS)
      ↓
  Laravel API (backend propio)
      ↓
   positions
      ↓
  trip builder
      ↓
    trips
      ↓
 feature engineering
      ↓
   ETA model
      ↓
 delay / anomaly logic
      ↓
   dashboard
```

Traccar se usa únicamente como fuente de telemetría — nunca se accede directamente a su base de datos interna; todo se consume vía su API a través de una capa `TraccarService`.

## Estructura del repositorio

```
backend/        Laravel — API, integración con Traccar, modelo de datos, trip builder
frontend/       Dashboard (Vue/React) — visualización, alertas, indicadores
data/
  raw/          Datos crudos sin procesar
  processed/    Datos limpios/transformados, listos para EDA y modelado
  synthetic/    Datos sintéticos generados (viajes simulados de flotilla)
notebooks/      Jupyter notebooks — EDA, feature engineering, experimentos de modelos
ml/             Código de modelado (entrenamiento, evaluación, modelo final)
docs/           Documentación del proyecto: esquema de datos, catálogo de zonas, decisiones de arquitectura
scripts/        Scripts de un solo uso (seeders, generador sintético, utilidades)
infra/          Configuración de despliegue (cron, systemd, etc.)
```

## Fuentes de datos: real vs. sintético

Actualmente solo existe **un** dispositivo GPS real (una unidad piloto) — insuficiente para EDA y modelado en las primeras semanas. Por eso el proyecto usa dos fuentes de datos, siempre diferenciadas explícitamente mediante el campo `data_source`:

- `data_source = 'real'` — telemetría real capturada vía Traccar.
- `data_source = 'synthetic'` — viajes simulados de una flotilla, generados siguiendo el mismo esquema que producirán los datos reales (ver [`docs/data-schema.md`](docs/data-schema.md)).

Los datos sintéticos nunca se presentan como reales, y ambas fuentes son distinguibles en todo momento.

## Equipo y roles

| Persona | Rol | Responsabilidad |
|---|---|---|
| Alejandra | Backend / Integración | Laravel, integración con Traccar, modelo de datos, `TripBuilderService`, endpoints API |
| — | Data / ML | Generador de datos sintéticos, EDA, feature engineering, baseline, modelos alternativos, modelo final de ETA |
| — | Dashboard / Frontend | Diseño y construcción del dashboard, visualización de mapas, alertas e indicadores |

## Estado actual

- Traccar + FMC920 + AWS EC2 funcionando; un dispositivo piloto instalado y en línea.
- Backend Laravel: en construcción (ver [`docs/`](docs/) para las decisiones de arquitectura ya definidas).
- Catálogo de zonas de Feraltar y esquema de datos: definidos (ver `docs/`).
- Generador de datos sintéticos: por construir.
- Dashboard: por construir.

## Documentación

- [`docs/data-schema.md`](docs/data-schema.md) — esquema de `trips`/`trip_features`, catálogo de `zones`, regla de data leakage.
- [`docs/zones.csv`](docs/zones.csv) — catálogo de zonas operativas de Feraltar (snapshot de referencia, no conectado a producción).

## Principios del proyecto

- Nunca hardcodear credenciales, IPs, tokens ni información sensible — todo vía variables de entorno.
- No se accede directamente a la base de datos interna de Traccar ni a la base de datos de producción de Feraltar — el proyecto es autocontenido.
- Datos reales y sintéticos siempre diferenciables mediante `data_source`.
- Ninguna feature usada en el modelo de ETA puede depender de información del viaje que no exista al momento de iniciarlo (ver regla de data leakage en `docs/data-schema.md`).
