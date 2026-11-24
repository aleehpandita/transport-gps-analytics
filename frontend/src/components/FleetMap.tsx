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
import type { MapZone, Vehicle, VehicleIcon } from '../types/api'
import { DEFAULT_VEHICLE_COLOR, ICON_SIZE, vehicleSvg } from '../lib/vehicleIcon'
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

// Estilos que Leaflet necesita como CSS normal: el pulso de los íconos (HTML suelto, sin
// Tailwind) y las etiquetas de zona (tooltips de Leaflet sin su caja blanca por defecto).
const MAP_CSS = `
  @keyframes fleet-pulse {
    0% { transform: scale(1); opacity: .6; }
    100% { transform: scale(2.2); opacity: 0; }
  }
  /* Alerta sin leer: halo rojo que late y la unidad parpadea, como alarma */
  @keyframes fleet-alarm-halo {
    0% { transform: scale(.6); opacity: .85; }
    100% { transform: scale(1.6); opacity: 0; }
  }
  @keyframes fleet-alarm-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: .35; }
  }
  .fleet-alarm-halo { animation: fleet-alarm-halo 1s ease-out infinite; }
  .fleet-alarm-blink { animation: fleet-alarm-blink 1s ease-in-out infinite; }
  @media (prefers-reduced-motion: reduce) {
    .fleet-pulse { animation: none !important; display: none; }
    /* Sin animación: el halo rojo se queda fijo para que la alerta se siga notando */
    .fleet-alarm-halo { animation: none !important; transform: scale(1.1); opacity: .6; }
    .fleet-alarm-blink { animation: none !important; }
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

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'

// Color de los bordes de zona: neutro, para que no compita con los colores de estado
const ZONE_COLOR = '#9aa4bf'
// Radio por defecto de las zonas por punto, igual que ZoneMatchingService
const DEFAULT_POINT_RADIUS_KM = 0.5

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
        {/* Mapa claro u oscuro según el tema del panel (index.css) */}
        <TileLayer attribution={OSM_ATTRIBUTION} url={OSM_URL} className="map-tiles-dark" />

        {/* Esquina superior derecha: mostrar u ocultar las zonas */}
        <LayersControl position="topright">

          <LayersControl.Overlay name="Zonas" checked>
            <LayerGroup>
              <ZonesLayer zones={zones} />
            </LayerGroup>
          </LayersControl.Overlay>
        </LayersControl>

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
            icon={vehicle.icon ?? 'van'}
            color={vehicle.color}
            alarm={Boolean(vehicle.active_alert)}
            selected={vehicle.id === focus?.id}
          >
            {vehicle.active_alert && (
              <>
                <strong style={{ color: '#e5566b' }}>⚠ {vehicle.active_alert.message}</strong>
                <br />
              </>
            )}
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
  icon: VehicleIcon
  color: string | null
  alarm: boolean // alerta sin leer: parpadea con halo rojo
  selected: boolean
  children: ReactNode
}

// La unidad vista desde arriba, del color que eligió el cliente, girada hacia donde va y con
// su nombre encima. Con alerta sin leer parpadea con un halo rojo. Sin señal se ve deslavada.
// Se desliza entre posiciones.
function VehicleMarker({ name, lat, lng, course, status, icon, color, alarm, selected, children }: VehicleMarkerProps) {
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

  const stale = status === 'stale' || status === 'unknown'
  // Redondeado a 5° para no recrear el ícono por cambios de rumbo que no se notan
  const heading = Math.round(course / 5) * 5

  const leafletIcon = useMemo(() => {
    const scale = selected ? 1.2 : 1
    // Cuadro donde gira el carrito: cabe a lo largo en cualquier ángulo
    const box = Math.round(ICON_SIZE[icon][1] * scale) + 6
    const accent = color ?? DEFAULT_VEHICLE_COLOR

    const halo = alarm
      ? `<div class="fleet-alarm-halo" style="position:absolute;inset:-6px;border-radius:9999px;
                    background:radial-gradient(circle, rgba(229,86,107,.75) 0%, rgba(229,86,107,.35) 45%, rgba(229,86,107,0) 70%)"></div>`
      : ''

    // Elegida en la tabla: un aro suave del color de la unidad
    const ring = selected
      ? `<div style="position:absolute;inset:-2px;border-radius:9999px;border:3px solid ${accent};opacity:.45"></div>`
      : ''

    const carStyle = [
      'position:absolute;inset:0;display:flex;align-items:center;justify-content:center',
      `transform:rotate(${heading}deg)`,
      'filter:drop-shadow(0 2px 3px rgba(0,0,0,.45))',
      stale ? 'opacity:.55' : '',
    ].join(';')

    return L.divIcon({
      className: '', // sin el fondo blanco por defecto de leaflet-div-icon
      iconSize: [box, box],
      iconAnchor: [box / 2, box / 2],
      tooltipAnchor: [0, -box / 2],
      html: `
        <div style="position:relative;width:${box}px;height:${box}px">
          ${halo}
          ${ring}
          <div class="${alarm ? 'fleet-alarm-blink' : ''}" style="${carStyle}">
            ${vehicleSvg(icon, color, scale)}
          </div>
          <div style="position:absolute;bottom:${box + 2}px;left:50%;transform:translateX(-50%);
                      white-space:nowrap;padding:1px 7px;border-radius:9999px;
                      background:${alarm ? '#e5566b' : 'rgba(255,255,255,.95)'};
                      color:${alarm ? '#fff' : '#141a2a'};border:1.5px solid ${alarm ? '#e5566b' : accent};
                      box-shadow:0 1px 3px rgba(0,0,0,.35);font:600 11px/15px system-ui,sans-serif">
            ${escapeHtml(name)}
          </div>
        </div>`,
    })
  }, [name, icon, color, alarm, stale, selected, heading])

  return (
    <Marker ref={markerRef} position={initialPosition} icon={leafletIcon} zIndexOffset={alarm ? 2000 : selected ? 1000 : 0}>
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