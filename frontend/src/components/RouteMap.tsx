import { memo, useEffect, useMemo, useRef, useState } from 'react'
import { CircleMarker, LayersControl, MapContainer, Marker, Polyline, TileLayer, Tooltip, useMap, useMapEvents } from 'react-leaflet'
import L, { type LatLngTuple } from 'leaflet'
import type { RoutePoint, RouteTrip } from '../types/api'
import { indexAt, type PlaybackFrame } from '../hooks/usePlayback'
import { formatClock, formatDuration, toTimestamp } from '../lib/time'

// Colores de la ruta por velocidad. Mismos verdes, ámbares y rojos que los estados del panel,
// más un azul para carretera, que no existe como estado.
export const SPEED_BANDS = [
  { max: 15, color: '#e3a946', label: 'Lento o maniobrando', range: 'menos de 15 km/h' },
  { max: 60, color: '#3fb68b', label: 'Ciudad', range: '15 a 60 km/h' },
  { max: 90, color: '#5b8def', label: 'Carretera', range: '60 a 90 km/h' },
  { max: Infinity, color: '#e5566b', label: 'Rápido', range: 'más de 90 km/h' },
] as const

const GAP_COLOR = '#8792ad'
// Hueco sin datos: más de 2 min entre posiciones y más de 200 m de distancia.
// Se dibuja punteado para no inventar un camino que no conocemos.
const GAP_SECONDS = 120
const GAP_METERS = 200
// Paradas que se marcan en el mapa: los cortes entre viajes de al menos 3 minutos
const STOP_MARK_SECONDS = 180

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
// Misma preferencia de mapa base que el mapa en vivo (FleetMap)
const BASE_LAYER_STORAGE_KEY = 'fleet-map:base-layer'

interface Segment {
  color: string
  dashed: boolean
  tripId: number | null
  positions: LatLngTuple[]
}

interface RouteMapProps {
  points: RoutePoint[]
  trips: RouteTrip[]
  selectedTripId: number | null
  frame: PlaybackFrame | null
  follow: boolean
}

export function RouteMap({ points, trips, selectedTripId, frame, follow }: RouteMapProps) {
  const [initialBase] = useState(readBaseLayer)

  return (
    <MapContainer
      center={[21.1619, -86.8515]}
      zoom={11}
      scrollWheelZoom
      preferCanvas // miles de tramos: el lienzo dibuja mucho más rápido que SVG
      className="h-full min-h-[360px] w-full"
    >
      <LayersControl position="topright">
        <LayersControl.BaseLayer name="Oscuro" checked={initialBase === 'dark'}>
          <TileLayer attribution={OSM_ATTRIBUTION} url={OSM_URL} className="map-tiles-dark" />
        </LayersControl.BaseLayer>
        <LayersControl.BaseLayer name="Claro" checked={initialBase === 'light'}>
          <TileLayer attribution={OSM_ATTRIBUTION} url={OSM_URL} />
        </LayersControl.BaseLayer>
      </LayersControl>
      <RememberBaseLayer />

      <RouteLayer points={points} trips={trips} selectedTripId={selectedTripId} />
      <FitRoute points={points} trips={trips} selectedTripId={selectedTripId} />
      {frame && <Playhead frame={frame} follow={follow} />}
    </MapContainer>
  )
}

// La ruta y las paradas no cambian en cada cuadro de la reproducción: memo evita
// redibujar miles de tramos 60 veces por segundo.
const RouteLayer = memo(function RouteLayer({
  points,
  trips,
  selectedTripId,
}: {
  points: RoutePoint[]
  trips: RouteTrip[]
  selectedTripId: number | null
}) {
  const segments = useMemo(() => buildSegments(points, trips), [points, trips])
  const stops = useMemo(() => buildStops(points, trips), [points, trips])

  return (
    <>
      {segments.map((segment, i) => {
        const dimmed = selectedTripId !== null && segment.tripId !== selectedTripId
        const highlighted = selectedTripId !== null && segment.tripId === selectedTripId

        return (
          <Polyline
            key={i}
            positions={segment.positions}
            interactive={false}
            pathOptions={{
              color: segment.color,
              weight: highlighted ? 6 : 4,
              opacity: dimmed ? 0.22 : 0.92,
              dashArray: segment.dashed ? '6 8' : undefined,
              lineCap: 'round',
              lineJoin: 'round',
            }}
          />
        )
      })}

      {stops.map((stop) => (
        <CircleMarker
          key={stop.from}
          center={stop.at}
          radius={stop.kind === 'stop' ? 6 : 7}
          pathOptions={{
            color: '#fff',
            weight: 2,
            fillColor: stop.kind === 'stop' ? '#3b4256' : stop.kind === 'start' ? '#3fb68b' : '#e5566b',
            fillOpacity: 1,
          }}
        >
          <Tooltip direction="top" offset={[0, -6]}>
            {stop.label}
          </Tooltip>
        </CircleMarker>
      ))}
    </>
  )
})

// Encuadra el día completo al cargarlo, y el viaje elegido cuando cambia la selección
function FitRoute({
  points,
  trips,
  selectedTripId,
}: {
  points: RoutePoint[]
  trips: RouteTrip[]
  selectedTripId: number | null
}) {
  const map = useMap()

  useEffect(() => {
    if (points.length === 0) return

    const trip = trips.find((t) => t.id === selectedTripId)
    const from = trip ? toTimestamp(trip.started_at) : -Infinity
    const to = trip ? toTimestamp(trip.ended_at) : Infinity

    const bounds = points.filter((p) => p[0] >= from && p[0] <= to).map((p): LatLngTuple => [p[1], p[2]])
    if (bounds.length === 0) return

    map.flyToBounds(bounds, { padding: [48, 48], maxZoom: 16, duration: 0.6 })
  }, [map, points, trips, selectedTripId])

  return null
}

// La camioneta reproduciéndose: círculo con flecha de rumbo. Si "Seguir unidad" está
// prendido, el mapa la acompaña (como mucho dos veces por segundo, para no marear).
function Playhead({ frame, follow }: { frame: PlaybackFrame; follow: boolean }) {
  const map = useMap()
  const lastPan = useRef(0)
  const heading = Math.round(frame.course / 5) * 5

  const icon = useMemo(
    () =>
      L.divIcon({
        className: '',
        iconSize: [34, 34],
        iconAnchor: [17, 17],
        html: `
          <div style="position:relative;width:34px;height:34px">
            <div style="position:absolute;inset:-8px;transform:rotate(${heading}deg)">
              <div style="position:absolute;top:0;left:50%;transform:translateX(-50%);width:0;height:0;
                          border-left:6px solid transparent;border-right:6px solid transparent;
                          border-bottom:9px solid #fff"></div>
            </div>
            <div style="width:100%;height:100%;border-radius:9999px;background:#3fb68b;border:3px solid #fff;
                        box-shadow:0 0 0 6px rgba(63,182,139,.25),0 2px 8px rgba(0,0,0,.6)"></div>
          </div>`,
      }),
    [heading],
  )

  useEffect(() => {
    if (!follow) return
    const now = performance.now()
    if (now - lastPan.current < 500) return
    lastPan.current = now
    map.panTo([frame.lat, frame.lng], { animate: true, duration: 0.4 })
  }, [map, follow, frame.lat, frame.lng])

  return <Marker position={[frame.lat, frame.lng]} icon={icon} interactive={false} zIndexOffset={1000} />
}

function RememberBaseLayer() {
  useMapEvents({
    baselayerchange: (event) => {
      try {
        localStorage.setItem(BASE_LAYER_STORAGE_KEY, event.name === 'Claro' ? 'light' : 'dark')
      } catch {
        // Sin almacenamiento: solo no se recuerda
      }
    },
  })
  return null
}

function readBaseLayer(): 'dark' | 'light' {
  try {
    return localStorage.getItem(BASE_LAYER_STORAGE_KEY) === 'light' ? 'light' : 'dark'
  } catch {
    return 'dark'
  }
}

function speedColor(speed: number): string {
  return SPEED_BANDS.find((band) => speed < band.max)?.color ?? SPEED_BANDS[SPEED_BANDS.length - 1].color
}

function metersBetween(a: RoutePoint, b: RoutePoint): number {
  const dLat = ((b[1] - a[1]) * Math.PI) / 180
  const dLng = ((b[2] - a[2]) * Math.PI) / 180
  const h =
    Math.sin(dLat / 2) ** 2 + Math.cos((a[1] * Math.PI) / 180) * Math.cos((b[1] * Math.PI) / 180) * Math.sin(dLng / 2) ** 2
  return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h))
}

// Parte la ruta en tramos del mismo color, viaje y tipo (normal o hueco sin datos).
// Cada tramo comparte su primer punto con el anterior, para que la línea no se corte.
function buildSegments(points: RoutePoint[], trips: RouteTrip[]): Segment[] {
  const ranges = trips.map((t) => ({ id: t.id, from: toTimestamp(t.started_at), to: toTimestamp(t.ended_at) }))
  const tripAt = (ts: number) => ranges.find((r) => ts >= r.from && ts <= r.to)?.id ?? null

  const segments: Segment[] = []
  let current: Segment | null = null

  for (let i = 1; i < points.length; i++) {
    const a = points[i - 1]
    const b = points[i]
    const dashed = b[0] - a[0] > GAP_SECONDS && metersBetween(a, b) > GAP_METERS
    const color = dashed ? GAP_COLOR : speedColor(b[3])
    const tripId = tripAt(b[0])

    if (current && current.color === color && current.dashed === dashed && current.tripId === tripId) {
      current.positions.push([b[1], b[2]])
    } else {
      current = { color, dashed, tripId, positions: [[a[1], a[2]], [b[1], b[2]]] }
      segments.push(current)
    }
  }

  return segments
}

interface StopMark {
  kind: 'start' | 'stop' | 'end'
  from: number
  at: LatLngTuple
  label: string
}

// Marcas del mapa: dónde empezó el día, cada parada de 3 min o más entre viajes, y dónde terminó
function buildStops(points: RoutePoint[], trips: RouteTrip[]): StopMark[] {
  if (points.length === 0) return []

  const pointAt = (ts: number): LatLngTuple => {
    const p = points[indexAt(points, ts)]
    return [p[1], p[2]]
  }

  const marks: StopMark[] = []
  const firstTs = points[0][0]
  const lastTs = points[points.length - 1][0]

  marks.push({ kind: 'start', from: firstTs - 1, at: [points[0][1], points[0][2]], label: `Primera posición del día, ${formatClock(firstTs)}` })

  for (let i = 0; i < trips.length - 1; i++) {
    const end = toTimestamp(trips[i].ended_at)
    const nextStart = toTimestamp(trips[i + 1].started_at)
    if (nextStart - end < STOP_MARK_SECONDS) continue

    const place = trips[i].destination_place ?? trips[i].destination_zone
    marks.push({
      kind: 'stop',
      from: end,
      at: pointAt(end),
      label: `Parada de ${formatDuration(nextStart - end)}, ${formatClock(end)} a ${formatClock(nextStart)}${place ? `, ${place}` : ''}`,
    })
  }

  marks.push({
    kind: 'end',
    from: lastTs + 1,
    at: [points[points.length - 1][1], points[points.length - 1][2]],
    label: `Última posición del día, ${formatClock(lastTs)}`,
  })

  return marks
}