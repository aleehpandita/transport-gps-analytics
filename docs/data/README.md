# Datos externos

## osm_hoteles.json

Hoteles y resorts de Tulum a Costa Mujeres, descargados de OpenStreetMap con Overpass
el 21 de octubre de 2026. Se importan con `php artisan places:import-osm` como lugares
candidatos (inactivos).

Datos © colaboradores de OpenStreetMap, disponibles bajo la licencia
Open Database License (ODbL): https://www.openstreetmap.org/copyright

Para actualizarlo:

```bash
curl -s -o docs/data/osm_hoteles.json -w "HTTP %{http_code}\n" \
  -A "transport-gps-analytics/1.0 (proyecto academico)" \
  --data-urlencode 'data=[out:json][timeout:90];(nwr["tourism"="hotel"]["name"](20.15,-87.55,21.35,-86.70);nwr["leisure"="resort"]["name"](20.15,-87.55,21.35,-86.70););out center tags;' \
  https://overpass-api.de/api/interpreter
```

## osm_semaforos.json

Semáforos (highway=traffic_signals) de Tulum a Costa Mujeres, descargados de OpenStreetMap
con Overpass el 22 de octubre de 2026: 538 nodos. Se importan con
`php artisan signals:import-osm`. Mismo aviso de licencia ODbL que arriba.