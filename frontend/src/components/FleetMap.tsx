import { useEffect, useRef } from 'react'
import { CircleMarker, MapContainer, TileLayer, Tooltip, useMap } from 'react-leaflet'
import type { LatLngTuple } from 'leaflet'
import type { Vehicle } from '../types/api'
import { formatLastSeen, formatSpeed } from '../lib/format'
import { STATUS_LABEL, vehicleStatus, type VehicleStatus } from '../lib/vehicleStatus'

// Centro urbano de Cancún: vista inicial mientras no hay posiciones
const DEFAULT_CENTER: LatLngTuple = [21.1619, -86.8515]
const DEFAULT_ZOOM = 11

// Mismos valores que los colores de estado en index.css (@theme).
// Leaflet dibuja en SVG con atributos, donde las variables CSS no funcionan.
const STATUS_COLOR: Record<VehicleStatus, string> = {
  moving: '#3fb68b',
  idling: '#e3a946',
  off: '#8792ad',
  stale: '#e5566b',
  unknown: '#8792ad',
}

interface FleetMapProps {
  vehicles: Vehicle[]
}

export function FleetMap({ vehicles }: FleetMapProps) {
  // Solo las unidades con posición; las demás no tienen dónde dibujarse
  const points = vehicles.flatMap((vehicle) =>
    vehicle.latest_position
      ? [{ vehicle, position: vehicle.latest_position, status: vehicleStatus(vehicle) }]
      : [],
  )

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

      {points.map(({ vehicle, position, status }) => (
        <CircleMarker
          key={vehicle.id}
          center={[position.latitude, position.longitude]}
          radius={8}
          pathOptions={{
            color: STATUS_COLOR[status],
            fillColor: STATUS_COLOR[status],
            fillOpacity: 0.85,
            weight: 2,
          }}
        >
          <Tooltip direction="top" offset={[0, -8]}>
            <strong>{vehicle.name}</strong>
            <br />
            {STATUS_LABEL[status]}, {formatSpeed(position.speed)}
            <br />
            Última señal: {formatLastSeen(position.device_time)}
          </Tooltip>
        </CircleMarker>
      ))}
    </MapContainer>
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