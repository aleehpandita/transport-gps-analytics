import { useMemo, useState } from 'react'
import { ArrivalsPanel } from './ArrivalsPanel'
import { FleetMap, type MapFocus } from './FleetMap'
import { FleetTable } from './FleetTable'
import { Header } from './Header'
import { NavLinks } from './NavLinks'
import { Panel } from './Panel'
import { TripsPanel } from './TripsPanel'
import { usePolling } from '../hooks/usePolling'
import {
  fetchLiveVehicles,
  fetchScheduledServices,
  fetchTodayTrips,
  fetchVehicles,
  fetchZones,
} from '../lib/apiClient'
import { formatKm } from '../lib/format'
import { ROLE_LABEL } from '../lib/roles'
import type { LivePosition, ScheduledService, User, Vehicle } from '../types/api'

const VEHICLES_INTERVAL_MS = 15_000
// Posición en vivo: el FMC920 envía cada 10 s en movimiento (Send Period 10055/10155/10255)
const LIVE_INTERVAL_MS = 5_000
const SERVICES_INTERVAL_MS = 60_000
// El Trip Builder corre cada 10 minutos; pedir más seguido no trae viajes nuevos
const TRIPS_INTERVAL_MS = 60_000
// Las zonas casi nunca cambian: basta con refrescarlas cada 10 minutos
const ZONES_INTERVAL_MS = 10 * 60_000

// Mismo umbral que vehicleStatus.ts: menos de 3 km/h cuenta como detenida
const IDLE_SPEED_KMH = 3

// Sin módulo de Operación no hay servicios programados: no se le pregunta a la API.
// Vive fuera del componente para que usePolling reciba siempre la misma función.
const noScheduledServices = (): Promise<ScheduledService[]> => Promise.resolve([])

interface DashboardProps {
  user: User
  onLogout: () => void
}

// El panel completo. Solo se monta con sesión: al cerrar sesión se desmonta y todos los
// usePolling se detienen solos.
export function Dashboard({ user, onLogout }: DashboardProps) {
  const vehicles = usePolling(fetchVehicles, VEHICLES_INTERVAL_MS)
  const live = usePolling(fetchLiveVehicles, LIVE_INTERVAL_MS)
  const services = usePolling(user.has_operation ? fetchScheduledServices : noScheduledServices, SERVICES_INTERVAL_MS)
  const trips = usePolling(fetchTodayTrips, TRIPS_INTERVAL_MS)
  const zones = usePolling(fetchZones, ZONES_INTERVAL_MS)

  // Unidad elegida en la tabla. Vive aquí porque la tabla y el mapa son hermanos:
  // la tabla la cambia y el mapa vuela hacia ella.
  const [mapFocus, setMapFocus] = useState<MapFocus | null>(null)
  const focusVehicle = (id: number) => setMapFocus({ id, at: Date.now() })

  // La flota de la base (cada 15 s) con la posición en vivo encima (cada 5 s).
  // Así el mapa, la tabla y el encabezado ven la misma posición.
  const fleet = useMemo(() => withLivePositions(vehicles.data ?? [], live.data ?? []), [vehicles.data, live.data])

  // Viajes de hoy: los de toda la flota, o solo los de la unidad elegida en la tabla.
  // Las tarjetas del encabezado (En ruta, Con señal, Recorrido hoy) siempre son de toda la flota.
  const selectedVehicle = mapFocus ? (fleet.find((v) => v.id === mapFocus.id) ?? null) : null
  const allTrips = trips.data ?? []
  const todayTrips = selectedVehicle ? allTrips.filter((t) => t.vehicle_id === selectedVehicle.id) : allTrips
  const todayKm = todayTrips.reduce((sum, trip) => sum + trip.distance_km, 0)
  const tripsCount = `${todayTrips.length} ${todayTrips.length === 1 ? 'viaje' : 'viajes'}, ${formatKm(todayKm)}`
  const tripsSummary = selectedVehicle ? (
    <span className="flex items-baseline gap-3">
      <span>
        <span className="text-white/80">{selectedVehicle.name}:</span> {tripsCount}
      </span>
      <button
        type="button"
        onClick={() => setMapFocus(null)}
        className="rounded border border-line px-2 py-0.5 hover:bg-surface-raised"
      >
        Ver todas
      </button>
    </span>
  ) : (
    tripsCount
  )

  return (
    <div className="flex min-h-full flex-col">
      <Header vehicles={fleet} updatedAt={vehicles.updatedAt} error={vehicles.error} />

      <div className="flex items-center justify-end gap-3 border-b border-line px-4 py-2 text-sm text-ink-muted lg:px-6">
        <div className="mr-auto">
          <NavLinks current="live" user={user} />
        </div>
        <span>
          {user.name}
          <span className="ml-2 font-mono text-[11px] tracking-[0.14em] uppercase">
            {ROLE_LABEL[user.role]}
          </span>
        </span>
        <button
          type="button"
          onClick={onLogout}
          className="rounded-md border border-line px-3 py-1 hover:bg-surface-raised"
        >
          Salir
        </button>
      </div>

      {vehicles.error && (
        <div role="alert" className="border-b border-late/40 bg-late/10 px-6 py-2 text-sm text-late">
          No se pudo actualizar la flota ({vehicles.error}). Revisa que Laravel esté corriendo en el puerto 8000.
        </div>
      )}

      <main className="grid flex-1 gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_380px] lg:p-6">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel index={1} title="Mapa de flota" className="min-h-[360px] flex-1">
            <FleetMap vehicles={fleet} zones={zones.data ?? []} focus={mapFocus} />
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
          {/* Módulo de Operación: las predicciones salen de los servicios programados */}
          {user.has_operation && (
            <Panel index={3} title="Predicciones de llegada">
              <ArrivalsPanel
                services={services.data ?? []}
                vehicles={fleet}
                loading={services.loading}
              />
            </Panel>
          )}

          <Panel index={user.has_operation ? 4 : 3} title="Viajes de hoy" aside={tripsSummary}>
            <TripsPanel
              trips={todayTrips}
              loading={trips.loading}
              emptyMessage={selectedVehicle ? `${selectedVehicle.name} todavía no tiene viajes hoy.` : undefined}
            />
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