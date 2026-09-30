# Zonificación maestra — Riviera Maya, Cancún y Costa Mujeres

**Estado: zonificación conceptual cerrada (2026-09-29).** Lista para fase de coordenadas/polígonos y validación técnica. El siguiente trabajo no es volver a discutir las zonas — es convertir cada decisión en geometría (coordenadas, líneas de corte, polígonos GeoJSON) y validar que no existan huecos ni traslapes con hoteles/posiciones GPS reales conocidos.

Fuente: investigación de campo con choferes, operaciones y referencias históricas (no copiadas automáticamente de la BD de Cabsi/Feraltar, que agrupaba por tarifa, no por geografía).

## Criterios definitivos

| # | Regla | Decisión |
|---|---|---|
| 1 | Prioridad de frontera | Ubicación/coordenadas > continuidad vial > límites físicos/urbanísticos > hoteles de referencia > criterio operativo de drivers > BD histórica |
| 2 | Complejos grandes | No dividir un mismo mega-complejo por nombres comerciales internos (Barceló, Palladium, Bahía Príncipe se mantienen completos dentro de una zona) |
| 3 | Fronteras GPS | Cuando exista un límite físico/vial/urbanístico estable, se prefiere sobre un hotel — los hoteles funcionan como anclas operativas, no como frontera en sí |
| 4 | Tarifas | Zona geográfica ≠ zona tarifaria. La BD anterior es solo evidencia histórica, puede contener agrupaciones hechas por precio |
| 5 | Implementación | Hotel/posición lat-lng → point-in-polygon → zona geográfica → tarifa/regla del cliente (esto último, fuera del alcance de esta capa) |

## Corredor principal — decisiones cerradas

| Zona | Inicio / criterio | Alcance |
|---|---|---|
| Cancún | Una sola zona: ciudad + Zona Hotelera juntas (no separadas). Polígono urbano. | Respeta zonas independientes ya definidas, como Puerto Juárez |
| Puerto Juárez | Zona propia. SM84-SM86 / corredor Puerto Juárez, incluye ferry y área terrestre | Cierre hacia transición Punta Sam |
| Playa Mujeres | Corredor al norte de Puerto Juárez/Punta Sam | Termina antes de TRS/Grand Palladium Costa Mujeres |
| Costa Mujeres | Inicia en TRS Coral / Grand Palladium Costa Mujeres | Final en límite urbanístico Costa Mujeres-Isla Blanca |
| Puerto Morelos | Inicio operativo: Haven Riviera Cancún | Zona propia, no se absorbe en Cancún |
| Paraíso Beach | Corredor Iberostar/Valentin | Separada de Puerto Morelos y Maroma |
| Maroma Beach | Corredor Maroma/Kanai (Belmond Maroma, Kanai) | Termina antes de BlueBay |
| Playa del Carmen | Inicio: BlueBay Grand Esmeralda | Incluye corredor PDC/Mayakoba hasta límite físico de Playacar |
| Playacar | Polígono físico del desarrollo | Zona propia, límite del desarrollo, no un hotel individual |
| Xcaret | Complejo/corredor Xcaret | Termina antes de Punta Venado |
| Calica | Destino puntual (Calica/Punta Venado Port) | No es corredor hotelero |
| Paamul | Inicio: Punta Venado | Termina antes de Hard Rock Riviera Maya |
| Puerto Aventuras | Inicio: Hard Rock Riviera Maya | Incluye todo el complejo Barceló Maya |
| Xpu-Ha / Kantenah | Zona combinada de trabajo | Después de Barceló: Catalonia Royal Tulum, Hotel Esencia, UNICO, Grand Palladium completo |
| Akumal | Ancla norte: Grand Sirenis | Incluye todo Bahía Príncipe |
| Tulum | Ancla de inicio: Hilton Tulum | Dreams Tulum como referencia operativa cercana |
| Tulum Hotel Zone | Corredor costero Tulum-Boca Paila | Inicia al entrar al corredor costero, final en Arco Maya |
| Boca Paila | Inicio operativo: Arco Maya | Desde acceso a Sian Ka'an hacia Boca Paila/Punta Allen |

## Destinos especiales y foráneos

No requieren tratamiento hotel-por-hotel — se modelan como punto operativo, área de destino, o polígono urbano.

| Zona | Tipo | Regla |
|---|---|---|
| Isla Mujeres | Punto operativo terrestre | Muelle/terminal en Puerto Juárez — la unidad no cruza a la isla |
| Cozumel | Punto operativo terrestre | Muelle de ferry en Playa del Carmen — la unidad no cruza a Cozumel |
| Chiquilá | Destino foráneo | Área/punto de llegada, asociado al acceso marítimo hacia Holbox |
| Valladolid | Destino foráneo | Polígono urbano/área de destino |
| Chichén Itzá | Destino turístico | Área/punto del complejo arqueológico y accesos |
| Mérida | Destino foráneo | Polígono urbano/área de destino, sin subdivisión hotelera en esta versión |
| Chetumal | Destino foráneo | Polígono urbano/área de destino, sin subdivisión hotelera en esta versión |

## Fronteras/anclas que no deben perderse (referencia rápida)

- BlueBay Grand Esmeralda = inicio de Playa del Carmen
- Hard Rock Riviera Maya = inicio de Puerto Aventuras
- Todo Barceló Maya = Puerto Aventuras
- Grand Palladium = Kantenah, dentro de la zona combinada Xpu-Ha/Kantenah
- Bahía Príncipe completo = Akumal
- Hilton Tulum = referencia de inicio de Tulum
- Arco Maya = frontera Tulum Hotel Zone / Boca Paila
- TRS Coral / Grand Palladium Costa Mujeres = inicio de Costa Mujeres

## Modelo de campos para la geometría (implementación técnica)

| Campo | Ejemplo / uso |
|---|---|
| `zone_id` | Identificador estable de la zona |
| `name` | Nombre comercial/geográfico acordado |
| `geometry_type` | `Polygon` / `MultiPolygon` / `Point` / `DestinationArea` |
| `north_boundary` / `south_boundary` | Referencia humana para auditoría y soporte (no usada en el cálculo) |
| `geometry` | GeoJSON usado por el motor de point-in-polygon |
| `reference_hotels` | Hoteles/anclas para pruebas y QA del polígono |
| `notes` | Excepciones operativas, ferry, complejos completos, etc. |

Ver columnas correspondientes agregadas a `zones` en [`data-schema.md`](data-schema.md).

## Siguiente etapa técnica

La definición conceptual está cerrada. Falta: coordenadas, puntos, líneas de corte y polígonos GeoJSON por zona, y validación de que no existan huecos ni traslapes, probando contra hoteles conocidos y posiciones GPS reales.