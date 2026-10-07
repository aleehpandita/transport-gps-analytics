import { ArrivalsPanel } from './components/ArrivalsPanel'
import { FleetMap } from './components/FleetMap'
import { FleetTable } from './components/FleetTable'
import { Header } from './components/Header'
import { Panel } from './components/Panel'
import { TripsPanel } from './components/TripsPanel'
import { usePolling } from './hooks/usePolling'
import { fetchScheduledServices, fetchTodayTrips, fetchVehicles } from './lib/apiClient'
import { formatKm } from './lib/format'

const VEHICLES_INTERVAL_MS = 15_000
const SERVICES_INTERVAL_MS = 60_000
// El Trip Builder corre cada 10 minutos; pedir más seguido no trae viajes nuevos
const TRIPS_INTERVAL_MS = 60_000

export default function App() {
  const vehicles = usePolling(fetchVehicles, VEHICLES_INTERVAL_MS)
  const services = usePolling(fetchScheduledServices, SERVICES_INTERVAL_MS)
  const trips = usePolling(fetchTodayTrips, TRIPS_INTERVAL_MS)

  const fleet = vehicles.data ?? []
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
            <FleetMap vehicles={fleet} />
          </Panel>

          <Panel index={2} title="Unidades" aside={`${fleet.length} en total`}>
            <FleetTable vehicles={fleet} loading={vehicles.loading} />
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