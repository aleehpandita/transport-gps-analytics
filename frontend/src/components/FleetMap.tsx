import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import {
  Circle,
  GeoJSON,
  LayerGroup,
  LayersControl,
  MapContainer,
  Marker,
  TileLayer,
  Tooltip,
  useMap,
  useMapEvents,
} from 'react-leaflet'
import L, { type LatLngTuple } from 'leaflet'
import type { MapZone, Vehicle } from '../types/api'
import { formatLastSeen, formatSpeed } from '../lib/format'
import { statusLabel, vehicleStatus, type VehicleStatus } from '../lib/vehicleStatus'

// Centro urbano de Cancún: vista inicial mientras no hay posiciones
const DEFAULT_CENTER: LatLngTuple = [21.1619, -86.8515]
const DEFAULT_ZOOM = 11
// Zoom al que se acerca el mapa al elegir una unidad en la tabla
const FOCUS_ZOOM = 15

// Cuánto tarda la camioneta en deslizarse a su nueva posición. Un poco menos que el
// polling en vivo (5 s), para que termine de llegar antes de la siguiente.
const GLIDE_MS = 4_500
// Si el salto es mayor (por ejemplo, la primera posición después de horas sin señal),
// se coloca directo en lugar de cruzar la ciudad volando.
const MAX_GLIDE_METERS = 2_000

// Mismos valores que los colores de estado en index.css (@theme).
// Leaflet dibuja los íconos como HTML suelto, donde las clases de Tailwind no aplican.
const STATUS_COLOR: Record<VehicleStatus, string> = {
  moving: '#3fb68b',
  stopped: '#3fb68b',
  parked: '#e3a946',
  stale: '#e5566b',
  unknown: '#8792ad',
}

// Camioneta vista de lado, en blanco, para ir dentro del círculo de color
const VAN_SVG = `
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M3 16V7a1 1 0 0 1 1-1h11l4 4h1a1 1 0 0 1 1 1v5" />
    <path d="M15 6v4h5" />
    <path d="M3 16h2m4 0h6m4 0h2" />
    <circle cx="7" cy="17" r="2" />
    <circle cx="17" cy="17" r="2" />
  </svg>`

// Estilos que Leaflet necesita como CSS normal: el pulso de los íconos (HTML suelto, sin
// Tailwind) y las etiquetas de zona (tooltips de Leaflet sin su caja blanca por defecto).
const MAP_CSS = `
  @keyframes fleet-pulse {
    0% { transform: scale(1); opacity: .6; }
    100% { transform: scale(2.2); opacity: 0; }
  }
  @media (prefers-reduced-motion: reduce) {
    .fleet-pulse { animation: none !important; display: none; }
  }
  .zone-label {
    background: transparent;
    border: 0;
    box-shadow: none;
    padding: 0;
    color: #fff;
    font: 600 12px/1.2 system-ui, sans-serif;
    letter-spacing: .02em;
    text-shadow: 0 0 3px #000, 0 0 3px #000, 0 0 6px rgba(0,0,0,.8);
    white-space: nowrap;
  }
  .zone-label::before { display: none; }`

// Mapas base. "Oscuro" es el de siempre con el filtro de index.css; "Claro" es el
// OpenStreetMap normal, igual al de Traccar, que se lee mejor de día y en el celular.
const BASE_LAYERS = {
  dark: 'Oscuro',
  light: 'Claro',
} as const
type BaseLayerKey = keyof typeof BASE_LAYERS

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'

// Color de los bordes de zona: neutro, para que no compita con los colores de estado
const ZONE_COLOR = '#9aa4bf'
// Radio por defecto de las zonas por punto, igual que ZoneMatchingService
const DEFAULT_POINT_RADIUS_KM = 0.5

// El mapa base elegido se recuerda en este navegador. Si el almacenamiento no está
// disponible (navegación privada, por ejemplo), simplemente arranca en oscuro.
const BASE_LAYER_STORAGE_KEY = 'fleet-map:base-layer'

function readBaseLayer(): BaseLayerKey {
  try {
    const saved = localStorage.getItem(BASE_LAYER_STORAGE_KEY)
    return saved === 'light' || saved === 'dark' ? saved : 'dark'
  } catch {
    return 'dark'
  }
}

function saveBaseLayer(key: BaseLayerKey): void {
  try {
    localStorage.setItem(BASE_LAYER_STORAGE_KEY, key)
  } catch {
    // Sin almacenamiento: no pasa nada, solo no se recuerda
  }
}

export interface MapFocus {
  id: number
  at: number // Date.now() del clic: permite volver a centrar aunque sea la misma unidad
}

interface FleetMapProps {
  vehicles: Vehicle[]
  zones?: MapZone[]
  focus?: MapFocus | null
}

export function FleetMap({ vehicles, zones = [], focus = null }: FleetMapProps) {
  const [initialBaseLayer] = useState(readBaseLayer)

  // Seguir a la unidad elegida: se prende con cada clic en la tabla y se apaga en cuanto
  // el usuario arrastra el mapa (quiere ver otra cosa) o con el botón "Dejar de seguir".
  // Se guarda el "at" del clic que lo prendió: un clic nuevo vuelve a seguir sin un efecto.
  const [followingAt, setFollowingAt] = useState<number | null>(null)
  const following = focus !== null && followingAt === focus.at
  const startFollowing = () => focus && setFollowingAt(focus.at)
  const stopFollowing = () => setFollowingAt(null)

  // Solo las unidades con posición; las demás no tienen dónde dibujarse
  const points = vehicles.flatMap((vehicle) =>
    vehicle.latest_position
      ? [{ vehicle, position: vehicle.latest_position, status: vehicleStatus(vehicle) }]
      : [],
  )

  const focusTarget = points.find(({ vehicle }) => vehicle.id === focus?.id)

  return (
    <div className="relative h-full min-h-[320px] w-full">
      <style>{MAP_CSS}</style>

      <MapContainer
        center={DEFAULT_CENTER}
        zoom={DEFAULT_ZOOM}
        scrollWheelZoom
        className="h-full min-h-[320px] w-full rounded-b-lg"
      >
        {/* Selector de la esquina superior derecha: mapa base y capa de zonas */}
        <LayersControl position="topright">
          <LayersControl.BaseLayer name={BASE_LAYERS.dark} checked={initialBaseLayer === 'dark'}>
            <TileLayer attribution={OSM_ATTRIBUTION} url={OSM_URL} className="map-tiles-dark" />
          </LayersControl.BaseLayer>
          <LayersControl.BaseLayer name={BASE_LAYERS.light} checked={initialBaseLayer === 'light'}>
            <TileLayer attribution={OSM_ATTRIBUTION} url={OSM_URL} />
          </LayersControl.BaseLayer>

          <LayersControl.Overlay name="Zonas" checked>
            <LayerGroup>
              <ZonesLayer zones={zones} />
            </LayerGroup>
          </LayersControl.Overlay>
        </LayersControl>

        <RememberBaseLayer />

        <FitToPoints points={points.map(({ position }) => [position.latitude, position.longitude])} />

        {focus && focusTarget && (
          <FlyToVehicle
            at={focus.at}
            point={[focusTarget.position.latitude, focusTarget.position.longitude]}
            onArrive={() => setFollowingAt(focus.at)}
          />
        )}

        {following && focusTarget && (
          <FollowVehicle
            lat={focusTarget.position.latitude}
            lng={focusTarget.position.longitude}
            onUserMove={stopFollowing}
          />
        )}

        {points.map(({ vehicle, position, status }) => (
          <VehicleMarker
            key={vehicle.id}
            name={vehicle.name}
            lat={position.latitude}
            lng={position.longitude}
            course={position.course}
            status={status}
            selected={vehicle.id === focus?.id}
          >
            <strong>{vehicle.name}</strong>
            {vehicle.current_zone && <> · {vehicle.current_zone.name}</>}
            <br />
            {statusLabel(vehicle, status)}, {formatSpeed(position.speed)}
            <br />
            Última señal: {formatLastSeen(position.device_time)}
          </VehicleMarker>
        ))}
      </MapContainer>

      {/* Aviso de seguimiento, arriba al centro del mapa */}
      {focus && focusTarget && (
        <div className="pointer-events-none absolute inset-x-0 top-3 z-[1000] flex justify-center px-14">
          <div className="pointer-events-auto flex items-center gap-3 rounded-full border border-line bg-surface/95 py-1 pr-1 pl-4 text-sm shadow-lg">
            <span className="truncate">
              {following ? 'Siguiendo a ' : ''}
              <strong>{focusTarget.vehicle.name}</strong>
            </span>
            <button
              type="button"
              onClick={following ? stopFollowing : startFollowing}
              className={`rounded-full px-3 py-1 font-medium whitespace-nowrap ${
                following ? 'bg-surface-raised hover:brightness-125' : 'bg-ok text-black hover:brightness-110'
              }`}
            >
              {following ? 'Dejar de seguir' : 'Seguir'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

interface VehicleMarkerProps {
  name: string
  lat: number
  lng: number
  course: number
  status: VehicleStatus
  selected: boolean
  children: ReactNode
}

// Círculo del color del estado con una camioneta blanca y el nombre debajo.
// En ruta: flecha hacia donde va y un pulso alrededor. Se desliza entre posiciones.
function VehicleMarker({ name, lat, lng, course, status, selected, children }: VehicleMarkerProps) {
  const markerRef = useRef<L.Marker | null>(null)

  // React-Leaflet movería el marcador de golpe si cambiara "position", así que se le da
  // solo la primera y después lo mueve el deslizamiento de abajo.
  const [initialPosition] = useState<LatLngTuple>([lat, lng])

  useEffect(() => {
    const marker = markerRef.current
    if (!marker) return

    const from = marker.getLatLng()
    const to = L.latLng(lat, lng)

    if (from.equals(to)) return
    if (from.distanceTo(to) > MAX_GLIDE_METERS) {
      marker.setLatLng(to)
      return
    }

    const startedAt = performance.now()
    let frame = 0

    const step = (now: number) => {
      const t = Math.min(1, (now - startedAt) / GLIDE_MS)
      marker.setLatLng([from.lat + (to.lat - from.lat) * t, from.lng + (to.lng - from.lng) * t])
      if (t < 1) frame = requestAnimationFrame(step)
    }

    frame = requestAnimationFrame(step)
    return () => cancelAnimationFrame(frame)
  }, [lat, lng])

  const moving = status === 'moving'
  // Redondeado a 5° para no recrear el ícono por cambios de rumbo que no se notan
  const heading = Math.round(course / 5) * 5

  const icon = useMemo(() => {
    const size = selected ? 40 : 32
    const color = STATUS_COLOR[status]
    const ring = selected ? `0 0 0 4px ${color}55,` : ''

    const pulse = moving
      ? `<div class="fleet-pulse" style="position:absolute;inset:0;border-radius:9999px;background:${color};
                      animation:fleet-pulse 1.8s ease-out infinite"></div>`
      : ''

    // Triángulo en el borde del círculo, girado según el rumbo (0° = norte)
    const arrow = moving
      ? `<div style="position:absolute;inset:-9px;transform:rotate(${heading}deg)">
           <div style="position:absolute;top:0;left:50%;transform:translateX(-50%);width:0;height:0;
                       border-left:6px solid transparent;border-right:6px solid transparent;
                       border-bottom:9px solid ${color};filter:drop-shadow(0 0 1px #fff)"></div>
         </div>`
      : ''

    return L.divIcon({
      className: '', // sin el fondo blanco por defecto de leaflet-div-icon
      iconSize: [size, size],
      iconAnchor: [size / 2, size / 2],
      tooltipAnchor: [0, -size / 2],
      html: `
        <div style="position:relative;width:${size}px;height:${size}px">
          ${pulse}
          ${arrow}
          <div style="position:relative;width:100%;height:100%;border-radius:9999px;background:${color};
                      border:2px solid #fff;box-shadow:${ring}0 2px 6px rgba(0,0,0,.55);
                      display:flex;align-items:center;justify-content:center">
            ${VAN_SVG}
          </div>
          <div style="position:absolute;top:${size + 4}px;left:50%;transform:translateX(-50%);
                      white-space:nowrap;padding:1px 6px;border-radius:4px;background:rgba(15,18,28,.85);
                      color:#fff;font:600 11px/16px system-ui,sans-serif">
            ${escapeHtml(name)}
          </div>
        </div>`,
    })
  }, [name, status, selected, moving, heading])

  return (
    <Marker ref={markerRef} position={initialPosition} icon={icon} zIndexOffset={selected ? 1000 : 0}>
      <Tooltip direction="top">{children}</Tooltip>
    </Marker>
  )
}

// Las zonas de servicio: polígonos con borde suave y su nombre en el centro.
// Las zonas por punto (aeropuerto, parques, muelles) se dibujan como círculo con su radio.
function ZonesLayer({ zones }: { zones: MapZone[] }) {
  return (
    <>
      {zones.map((zone) => {
        if (zone.geometry) {
          return (
            <GeoJSON
              // La geometría no cambia sola; la llave fuerza a redibujar si se reimporta
              key={`${zone.id}-${zone.geometry_type}`}
              data={zone.geometry}
              style={{ color: ZONE_COLOR, weight: 1.5, opacity: 0.7, fillColor: ZONE_COLOR, fillOpacity: 0.06 }}
              interactive={false}
            >
              <Tooltip permanent direction="center" className="zone-label">
                {zone.name}
              </Tooltip>
            </GeoJSON>
          )
        }

        if (zone.latitude !== null && zone.longitude !== null) {
          const radiusKm = zone.geofence_radius_km ?? DEFAULT_POINT_RADIUS_KM

          return (
            <Circle
              key={zone.id}
              center={[zone.latitude, zone.longitude]}
              radius={radiusKm * 1000}
              pathOptions={{ color: ZONE_COLOR, weight: 1, opacity: 0.7, dashArray: '4 4', fillOpacity: 0.04 }}
              interactive={false}
            >
              <Tooltip permanent direction="center" className="zone-label">
                {zone.name}
              </Tooltip>
            </Circle>
          )
        }

        return null
      })}
    </>
  )
}

// Guarda el mapa base que elige el usuario, para abrir con el mismo la próxima vez
function RememberBaseLayer() {
  useMapEvents({
    baselayerchange: (event) => {
      const key = (Object.keys(BASE_LAYERS) as BaseLayerKey[]).find((k) => BASE_LAYERS[k] === event.name)
      if (key) saveBaseLayer(key)
    },
  })

  return null
}

// Encuadra el mapa una sola vez, cuando llegan las primeras posiciones.
// Después no vuelve a moverlo, para no pelear con el zoom del usuario en cada polling.
function FitToPoints({ points }: { points: LatLngTuple[] }) {
  const map = useMap()
  const fitted = useRef(false)

  useEffect(() => {
    if (fitted.current || points.length === 0) return
    fitted.current = true

    if (points.length === 1) {
      map.setView(points[0], 14)
    } else {
      map.fitBounds(points, { padding: [40, 40], maxZoom: 14 })
    }
  }, [map, points])

  return null
}

// Vuela a la unidad elegida en la tabla. Solo se dispara con un clic nuevo ("at"),
// no cuando la unidad se mueve: así no la persigue ni pelea con el usuario.
function FlyToVehicle({ at, point, onArrive }: { at: number; point: LatLngTuple; onArrive: () => void }) {
  const map = useMap()
  const lastAt = useRef<number | null>(null)

  useEffect(() => {
    if (lastAt.current === at) return
    lastAt.current = at
    map.flyTo(point, Math.max(map.getZoom(), FOCUS_ZOOM), { duration: 0.8 })
    // Al llegar empieza a seguirla. "once": si el usuario la interrumpe, no se dispara dos veces.
    map.once('moveend', onArrive)
  }, [map, at, point, onArrive])

  return null
}

// Mantiene a la unidad en el centro mientras se mueve. El paneo dura lo mismo que el
// deslizamiento del ícono (GLIDE_MS), así mapa e ícono avanzan juntos.
// Si el usuario arrastra el mapa, deja de seguir: quiere ver otra cosa.
function FollowVehicle({ lat, lng, onUserMove }: { lat: number; lng: number; onUserMove: () => void }) {
  const map = useMap()

  useMapEvents({ dragstart: onUserMove })

  useEffect(() => {
    map.panTo([lat, lng], { animate: true, duration: GLIDE_MS / 1000, easeLinearity: 1 })
  }, [map, lat, lng])

  return null
}

function escapeHtml(text: string): string {
  return text.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`)
}