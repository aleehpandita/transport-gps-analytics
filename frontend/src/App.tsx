import { useMemo, useState } from 'react'
import { ArrivalsPanel } from './components/ArrivalsPanel'
import { FleetMap, type MapFocus } from './components/FleetMap'
import { FleetTable } from './components/FleetTable'
import { Header } from './components/Header'
import { Panel } from './components/Panel'
import { TripsPanel } from './components/TripsPanel'
import { usePolling } from './hooks/usePolling'
import { fetchLiveVehicles, fetchScheduledServices, fetchTodayTrips, fetchVehicles } from './lib/apiClient'
import { formatKm } from './lib/format'
import type { LivePosition, Vehicle } from './types/api'

const VEHICLES_INTERVAL_MS = 15_000
// Posición en vivo: el FMC920 envía cada 10 s en movimiento (Send Period 10055/10155/10255)
const LIVE_INTERVAL_MS = 5_000
const SERVICES_INTERVAL_MS = 60_000
// El Trip Builder corre cada 10 minutos; pedir más seguido no trae viajes nuevos
const TRIPS_INTERVAL_MS = 60_000

// Mismo umbral que vehicleStatus.ts: menos de 3 km/h cuenta como detenida
const IDLE_SPEED_KMH = 3

export default function App() {
  const vehicles = usePolling(fetchVehicles, VEHICLES_INTERVAL_MS)
  const live = usePolling(fetchLiveVehicles, LIVE_INTERVAL_MS)
  const services = usePolling(fetchScheduledServices, SERVICES_INTERVAL_MS)
  const trips = usePolling(fetchTodayTrips, TRIPS_INTERVAL_MS)

  // Unidad elegida en la tabla. Vive aquí porque la tabla y el mapa son hermanos:
  // la tabla la cambia y el mapa vuela hacia ella.
  const [mapFocus, setMapFocus] = useState<MapFocus | null>(null)
  const focusVehicle = (id: number) => setMapFocus({ id, at: Date.now() })

  // La flota de la base (cada 15 s) con la posición en vivo encima (cada 5 s).
  // Así el mapa, la tabla y el encabezado ven la misma posición.
  const fleet = useMemo(() => withLivePositions(vehicles.data ?? [], live.data ?? []), [vehicles.data, live.data])
  const todayTrips = trips.data ?? []
  const todayKm = todayTrips.reduce((sum, trip) => sum + trip.distance_km, 0)
  const tripsSummary = `${todayTrips.length} ${todayTrips.length === 1 ? 'viaje' : 'viajes'}, ${formatKm(todayKm)}`

  return (
    <div className="flex min-h-full flex-col">
      <Header vehicles={fleet} updatedAt={vehicles.updatedAt} error={vehicles.error} />

      {vehicles.error && (
        <div role="alert" className="border-b border-late/40 bg-late/10 px-6 py-2 text-sm text-late">
          No se pudo actualizar la flota ({vehicles.error}). Revisa que Laravel esté corriendo en el puerto 8000.
        </div>
      )}

      <main className="grid flex-1 gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:p-6">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel index={1} title="Mapa de flota" className="min-h-[360px] flex-1">
            <FleetMap vehicles={fleet} focus={mapFocus} />
          </Panel>

          <Panel index={2} title="Unidades" aside={`${fleet.length} en total`}>
            <FleetTable
              vehicles={fleet}
              loading={vehicles.loading}
              selectedId={mapFocus?.id ?? null}
              onSelect={focusVehicle}
            />
          </Panel>
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          <Panel index={3} title="Predicciones de llegada">
            <ArrivalsPanel
              services={services.data ?? []}
              vehicles={fleet}
              loading={services.loading}
            />
          </Panel>

          <Panel index={4} title="Viajes de hoy" aside={tripsSummary}>
            <TripsPanel trips={todayTrips} loading={trips.loading} />
          </Panel>
        </div>
      </main>
    </div>
  )
}

// Pone la posición en vivo sobre la de la base, solo si es válida y más reciente.
// Si Traccar no respondió, se queda la de la base: el mapa nunca se queda vacío.
function withLivePositions(vehicles: Vehicle[], live: LivePosition[]): Vehicle[] {
  const liveById = new Map(live.map((p) => [p.vehicle_id, p]))

  return vehicles.map((vehicle) => {
    const p = liveById.get(vehicle.id)
    const stored = vehicle.latest_position

    if (!p || !p.valid || !stored) return vehicle
    if (new Date(p.device_time) <= new Date(stored.device_time)) return vehicle

    const isMoving = p.speed >= IDLE_SPEED_KMH

    return {
      ...vehicle,
      latest_position: {
        ...stored,
        latitude: p.latitude,
        longitude: p.longitude,
        speed: p.speed,
        course: p.course,
        ignition: p.ignition,
        device_time: p.device_time,
      },
      // Si va en movimiento, no está detenida. Si se acaba de detener, la parada empieza
      // en esta posición; si ya estaba detenida, se conserva la hora que calculó la base.
      stopped_since: isMoving ? null : (vehicle.stopped_since ?? p.device_time),
    }
  })
}