import { ArrivalsPanel } from './components/ArrivalsPanel'
import { FleetTable } from './components/FleetTable'
import { Header } from './components/Header'
import { MapPlaceholder } from './components/MapPlaceholder'
import { Panel } from './components/Panel'
import { usePolling } from './hooks/usePolling'
import { fetchScheduledServices, fetchVehicles } from './lib/apiClient'

const VEHICLES_INTERVAL_MS = 15_000
const SERVICES_INTERVAL_MS = 60_000

export default function App() {
  const vehicles = usePolling(fetchVehicles, VEHICLES_INTERVAL_MS)
  const services = usePolling(fetchScheduledServices, SERVICES_INTERVAL_MS)

  const fleet = vehicles.data ?? []

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
            <MapPlaceholder />
          </Panel>

          <Panel index={2} title="Unidades" aside={`${fleet.length} en total`}>
            <FleetTable vehicles={fleet} loading={vehicles.loading} />
          </Panel>
        </div>

        <Panel index={3} title="Predicciones de llegada">
          <ArrivalsPanel
            services={services.data ?? []}
            vehicles={fleet}
            loading={services.loading}
          />
        </Panel>
      </main>
    </div>
  )
}