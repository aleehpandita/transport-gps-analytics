import { useEffect, useMemo, useRef, type ReactNode } from 'react'
import { MapContainer, Marker, TileLayer, Tooltip, useMap } from 'react-leaflet'
import L, { type LatLngTuple } from 'leaflet'
import type { Vehicle } from '../types/api'
import { formatLastSeen, formatSpeed } from '../lib/format'
import { statusLabel, vehicleStatus, type VehicleStatus } from '../lib/vehicleStatus'

// Centro urbano de Cancún: vista inicial mientras no hay posiciones
const DEFAULT_CENTER: LatLngTuple = [21.1619, -86.8515]
const DEFAULT_ZOOM = 11
// Zoom al que se acerca el mapa al elegir una unidad en la tabla
const FOCUS_ZOOM = 15

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

export interface MapFocus {
  id: number
  at: number // Date.now() del clic: permite volver a centrar aunque sea la misma unidad
}

interface FleetMapProps {
  vehicles: Vehicle[]
  focus?: MapFocus | null
}

export function FleetMap({ vehicles, focus = null }: FleetMapProps) {
  // Solo las unidades con posición; las demás no tienen dónde dibujarse
  const points = vehicles.flatMap((vehicle) =>
    vehicle.latest_position
      ? [{ vehicle, position: vehicle.latest_position, status: vehicleStatus(vehicle) }]
      : [],
  )

  const focusTarget = points.find(({ vehicle }) => vehicle.id === focus?.id)

  return (
    <MapContainer
      center={DEFAULT_CENTER}
      zoom={DEFAULT_ZOOM}
      scrollWheelZoom
      className="h-full min-h-[320px] w-full rounded-b-lg"
    >
      <TileLayer
        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
        className="map-tiles-dark"
      />

      <FitToPoints points={points.map(({ position }) => [position.latitude, position.longitude])} />

      {focus && focusTarget && (
        <FlyToVehicle
          at={focus.at}
          point={[focusTarget.position.latitude, focusTarget.position.longitude]}
        />
      )}

      {points.map(({ vehicle, position, status }) => (
        <VehicleMarker
          key={vehicle.id}
          name={vehicle.name}
          point={[position.latitude, position.longitude]}
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
  )
}

interface VehicleMarkerProps {
  name: string
  point: LatLngTuple
  status: VehicleStatus
  selected: boolean
  children: ReactNode
}

// Círculo del color del estado con una camioneta blanca, y el nombre debajo.
// El borde blanco y la sombra lo separan del mapa oscuro, sin importar el color.
function VehicleMarker({ name, point, status, selected, children }: VehicleMarkerProps) {
  const icon = useMemo(() => {
    const size = selected ? 40 : 32
    const ring = selected ? `0 0 0 4px ${STATUS_COLOR[status]}55,` : ''

    return L.divIcon({
      className: '', // sin el fondo blanco por defecto de leaflet-div-icon
      iconSize: [size, size],
      iconAnchor: [size / 2, size / 2],
      tooltipAnchor: [0, -size / 2],
      html: `
        <div style="position:relative;width:${size}px;height:${size}px">
          <div style="width:100%;height:100%;border-radius:9999px;background:${STATUS_COLOR[status]};
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
  }, [name, status, selected])

  return (
    <Marker position={point} icon={icon} zIndexOffset={selected ? 1000 : 0}>
      <Tooltip direction="top">{children}</Tooltip>
    </Marker>
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

// Vuela a la unidad elegida en la tabla. Depende solo de "at" (el momento del clic),
// no de la posición: así no persigue a la unidad en cada polling ni pelea con el usuario.
function FlyToVehicle({ at, point }: { at: number; point: LatLngTuple }) {
  const map = useMap()
  const lastAt = useRef<number | null>(null)

  useEffect(() => {
    if (lastAt.current === at) return
    lastAt.current = at
    map.flyTo(point, Math.max(map.getZoom(), FOCUS_ZOOM), { duration: 0.8 })
  }, [map, at, point])

  return null
}

function escapeHtml(text: string): string {
  return text.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`)
}