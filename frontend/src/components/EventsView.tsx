import { useEffect, useMemo, useState } from 'react'
import { usePeriod } from '../hooks/usePeriod'
import { fetchEvents, fetchVehicles, type EventFilter, type ReportFilters } from '../lib/apiClient'
import { loadingFrom, shown, type LoadState } from '../lib/loadState'
import { cancunDateOf, formatClock, formatDayLabel, formatDuration } from '../lib/time'
import type { EventsPage, User, Vehicle, VehicleEventRow, VehicleEventType } from '../types/api'
import { NavLinks } from './NavLinks'
import { Pagination } from './Pagination'
import { PeriodFilters } from './PeriodFilters'

// Bitácora de eventos: alertas (exceso, parada larga, sin señal) y la operación del día
// (visitas a lobbies y terminales, entradas y salidas de zonas).

const ALERT_TYPES: VehicleEventType[] = ['overspeed', 'long_stop', 'signal_lost']

// Color por tipo: rojo lo grave, ámbar lo que hay que revisar, verde la operación normal
const TYPE_INFO: Record<VehicleEventType, { label: string; tone: string }> = {
  overspeed: { label: 'Exceso de velocidad', tone: 'bg-late' },
  long_stop: { label: 'Parada larga', tone: 'bg-risk' },
  signal_lost: { label: 'Sin señal', tone: 'bg-risk' },
  place_visit: { label: 'Visita a lugar', tone: 'bg-ok' },
  zone_enter: { label: 'Entrada a zona', tone: 'bg-ink-muted' },
  zone_exit: { label: 'Salida de zona', tone: 'bg-ink-muted' },
}

const FILTERS: { id: EventFilter; label: string }[] = [
  { id: 'alerts', label: 'Alertas' },
  { id: null, label: 'Todos' },
  { id: 'overspeed', label: 'Exceso de velocidad' },
  { id: 'long_stop', label: 'Parada larga' },
  { id: 'signal_lost', label: 'Sin señal' },
  { id: 'place_visit', label: 'Visitas a lugares' },
  { id: 'zone_enter', label: 'Entradas a zona' },
  { id: 'zone_exit', label: 'Salidas de zona' },
]

interface EventsViewProps {
  user: User
  onLogout: () => void
}

export function EventsView({ user, onLogout }: EventsViewProps) {
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [vehicleId, setVehicleId] = useState<number | null>(null)
  const period = usePeriod('today')
  const { range } = period
  const [type, setType] = useState<EventFilter>('alerts')
  const [events, setEvents] = useState<LoadState<EventsPage>>({ status: 'loading' })

  const filters: ReportFilters = useMemo(() => ({ from: range.from, to: range.to, vehicleId }), [range.from, range.to, vehicleId])

  // La página va amarrada a los filtros: si cambian, se regresa sola a la primera
  const pageKey = `${filters.from}|${filters.to}|${filters.vehicleId}|${type}`
  const [pageState, setPageState] = useState({ key: pageKey, page: 1 })
  const page = pageState.key === pageKey ? pageState.page : 1

  useEffect(() => {
    const controller = new AbortController()
    fetchVehicles(controller.signal)
      .then(setVehicles)
      .catch(() => {})
    return () => controller.abort()
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    setEvents(loadingFrom)
    fetchEvents(filters, type, page, controller.signal)
      .then((value) => {
        if (!controller.signal.aborted) setEvents({ status: 'ready', value })
      })
      .catch((e: unknown) => {
        if (controller.signal.aborted) return
        setEvents({ status: 'error', message: e instanceof Error ? e.message : 'Error desconocido' })
      })
    return () => controller.abort()
  }, [filters, type, page])

  function goToPage(next: number) {
    setPageState({ key: pageKey, page: next })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const report = shown(events)
  const counts = report?.counts ?? {}
  const countFor = (id: EventFilter) => {
    if (id === null) return Object.values(counts).reduce((s, n) => s + (n ?? 0), 0)
    if (id === 'alerts') return ALERT_TYPES.reduce((s, t) => s + (counts[t] ?? 0), 0)
    return counts[id] ?? 0
  }
  const manyVehicles = vehicleId === null && vehicles.length > 1
  const busy = events.status === 'loading'

  return (
    <div className="flex min-h-screen flex-col">
      <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 lg:px-6">
        <h1 className="text-lg font-semibold">Eventos</h1>
        <div className="ml-auto flex flex-wrap items-center gap-3 text-sm">
          <NavLinks current="events" />
          <span className="text-ink-muted">{user.name}</span>
          <button
            type="button"
            onClick={onLogout}
            className="rounded-md border border-line px-3 py-1.5 text-ink-muted hover:bg-surface-raised"
          >
            Salir
          </button>
        </div>
      </header>

      <PeriodFilters vehicles={vehicles} vehicleId={vehicleId} onVehicleChange={setVehicleId} period={period} />

      {/* Filtro por tipo, con cuántos hay de cada uno en el periodo */}
      <div
        className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 text-sm lg:px-6"
        role="group"
        aria-label="Tipo de evento"
      >
        {FILTERS.map((f) => {
          const active = type === f.id
          const n = countFor(f.id)
          return (
            <button
              key={f.id ?? 'all'}
              type="button"
              aria-pressed={active}
              onClick={() => setType(f.id)}
              className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 ${
                active ? 'border-ok bg-ok/15 font-medium text-white' : 'border-line text-ink-muted hover:bg-surface-raised'
              }`}
            >
              {f.label}
              <span className="tabular-nums text-xs">{n.toLocaleString('es-MX')}</span>
            </button>
          )
        })}
        <span className="ml-auto text-ink-muted">{period.label}</span>
      </div>

      <main className="flex-1 overflow-x-auto px-4 py-4 lg:px-6">
        {events.status === 'error' && (
          <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-sm text-late">
            No se pudieron cargar los eventos ({events.message}). Revisa que Laravel esté corriendo y vuelve a intentar.
          </p>
        )}
        {!report && events.status === 'loading' && <p className="py-6 text-sm text-ink-muted">Cargando…</p>}
        {report && report.data.length === 0 && (
          <p className="py-6 text-sm text-ink-muted">
            {type === 'alerts' ? 'Sin alertas en este periodo. Todo en orden.' : 'No hay eventos de este tipo en el periodo.'}
          </p>
        )}

        {report && report.data.length > 0 && (
          <>
            <ul className={`divide-y divide-line ${busy ? 'opacity-60' : ''}`}>
              {report.data.map((e) => (
                <EventItem key={e.id} event={e} showVehicle={manyVehicles} />
              ))}
            </ul>
            <Pagination meta={report.meta} busy={busy} noun="eventos" onPage={goToPage} />
          </>
        )}
      </main>
    </div>
  )
}

function EventItem({ event: e, showVehicle }: { event: VehicleEventRow; showVehicle: boolean }) {
  const info = TYPE_INFO[e.type]
  const date = cancunDateOf(e.started_at)
  const instant = e.type === 'zone_enter' || e.type === 'zone_exit'
  const ongoing = !instant && e.ended_at === null
  const where = e.place?.replace(/\s+lobby$/i, '') ?? e.zone ?? 'Sin zona'

  return (
    <li className="flex flex-wrap items-start gap-x-4 gap-y-1 py-3 text-sm">
      <span className={`mt-1.5 size-2.5 shrink-0 rounded-full ${info.tone}`} aria-hidden="true" />

      <div className="w-36 shrink-0 tabular-nums">
        <div>{formatDayLabel(date)}</div>
        <div className="text-ink-muted">
          {formatClock(e.started_at)}
          {!instant && e.ended_at && ` a ${formatClock(e.ended_at)}`}
        </div>
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium">{info.label}</span>
          {ongoing && <span className="rounded-full bg-risk/15 px-2 py-0.5 text-xs text-risk">En curso</span>}
          {showVehicle && <span className="text-ink-muted">{e.vehicle}</span>}
        </div>
        <div className="text-ink-muted">{describe(e, where)}</div>
      </div>

      <div className="flex shrink-0 gap-3">
        <a
          href={`#/historial/${e.vehicle_id}/${date}`}
          className="underline decoration-line underline-offset-4 hover:decoration-ok"
        >
          Ver en historial
        </a>
        <a
          href={`https://www.google.com/maps?q=${e.latitude},${e.longitude}`}
          target="_blank"
          rel="noreferrer"
          className="underline decoration-line underline-offset-4 hover:decoration-ok"
        >
          Mapa
        </a>
      </div>
    </li>
  )
}

// El detalle de cada evento en una línea, en palabras de operación
function describe(e: VehicleEventRow, where: string): string {
  const lasted = e.duration_seconds !== null ? formatDuration(e.duration_seconds) : null

  switch (e.type) {
    case 'overspeed':
      return `Hasta ${Math.round(e.value ?? 0)} km/h${lasted ? ` durante ${lasted}` : ''}, en ${where}`
    case 'long_stop':
      return `Detenida ${lasted ?? 'desde hace rato'} en ${where}, fuera de un lugar conocido`
    case 'signal_lost':
      return lasted ? `Sin reportar ${lasted}, la última vez en ${where}` : `Dejó de reportar en ${where}`
    case 'place_visit':
      return lasted ? `${where}: ${lasted} en el lugar` : `${where}: sigue ahí`
    case 'zone_enter':
      return `Entró a ${e.zone ?? 'una zona'}`
    case 'zone_exit':
      return `Salió de ${e.zone ?? 'una zona'}`
  }
}