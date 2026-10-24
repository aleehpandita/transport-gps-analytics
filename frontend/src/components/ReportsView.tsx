import { useEffect, useMemo, useState } from 'react'
import {
  fetchDailyReport,
  fetchStopsReport,
  fetchTripsReport,
  fetchVehicles,
  reportCsvUrl,
  type ReportFilters,
  type ReportKind,
} from '../lib/apiClient'
import { usePeriod } from '../hooks/usePeriod'
import { loadingFrom, shown, type LoadState } from '../lib/loadState'
import { cancunDateOf, formatClock, formatDayLabel, formatDuration } from '../lib/time'
import { tripLabel } from '../lib/tripLabel'
import type { DailyReportRow, StopsReport, TripsReport, User, Vehicle } from '../types/api'
import { NavLinks } from './NavLinks'
import { Pagination } from './Pagination'
import { PeriodFilters } from './PeriodFilters'

const SHORT_TRIP_KM = 2

const TABS: { id: ReportKind; label: string }[] = [
  { id: 'daily', label: 'Resumen diario' },
  { id: 'trips', label: 'Viajes' },
  { id: 'stops', label: 'Paradas' },
]

interface ReportsViewProps {
  user: User
  onLogout: () => void
}

export function ReportsView({ user, onLogout }: ReportsViewProps) {
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [vehicleId, setVehicleId] = useState<number | null>(null)
  const period = usePeriod('week')
  const { range } = period
  const [tab, setTab] = useState<ReportKind>('daily')
  const [showShort, setShowShort] = useState(false)

  const [daily, setDaily] = useState<LoadState<DailyReportRow[]>>({
    status: 'loading',
  })
  const [trips, setTrips] = useState<LoadState<TripsReport>>({
    status: 'loading',
  })
  const [stops, setStops] = useState<LoadState<StopsReport>>({
    status: 'loading',
  })

  const filters: ReportFilters = useMemo(() => ({ from: range.from, to: range.to, vehicleId }), [range.from, range.to, vehicleId])
  const minKm = showShort ? 0 : SHORT_TRIP_KM

  // La página va amarrada a los filtros: si cambian la pestaña, la unidad, las fechas o el
  // filtro de km, se regresa sola a la primera, sin pedir dos veces al servidor
  const pageKey = `${tab}|${filters.from}|${filters.to}|${filters.vehicleId}|${minKm}`
  const [pageState, setPageState] = useState({ key: pageKey, page: 1 })
  const page = pageState.key === pageKey ? pageState.page : 1

  useEffect(() => {
    const controller = new AbortController()
    fetchVehicles(controller.signal)
      .then(setVehicles)
      .catch(() => {})
    return () => controller.abort()
  }, [])

  // Solo se pide la pestaña que se está viendo; al cambiar filtros se vuelve a pedir
  useEffect(() => {
    const controller = new AbortController()
    const fail = (e: unknown) => {
      if (controller.signal.aborted) return
      const message = e instanceof Error ? e.message : 'Error desconocido'
      if (tab === 'daily') setDaily({ status: 'error', message })
      if (tab === 'trips') setTrips({ status: 'error', message })
      if (tab === 'stops') setStops({ status: 'error', message })
    }

    const ready =
      <T,>(set: (s: LoadState<T>) => void) =>
      (value: T) => {
        if (!controller.signal.aborted) set({ status: 'ready', value })
      }

    if (tab === 'daily') {
      setDaily(loadingFrom)
      fetchDailyReport(filters, controller.signal).then(ready(setDaily)).catch(fail)
    } else if (tab === 'trips') {
      setTrips(loadingFrom)
      fetchTripsReport(filters, { page, minKm }, controller.signal).then(ready(setTrips)).catch(fail)
    } else {
      setStops(loadingFrom)
      fetchStopsReport(filters, { page }, controller.signal).then(ready(setStops)).catch(fail)
    }

    return () => controller.abort()
  }, [tab, filters, page, minKm])

  const manyVehicles = vehicleId === null && vehicles.length > 1

  function goToPage(next: number) {
    setPageState({ key: pageKey, page: next })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  return (
    <div className="flex min-h-screen flex-col">
      <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 lg:px-6">
        <h1 className="text-lg font-semibold">Reportes</h1>
        <div className="ml-auto flex flex-wrap items-center gap-3 text-sm">
          <NavLinks current="reports" />
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

      {/* Pestañas y descarga */}
      <div className="flex flex-wrap items-center gap-3 border-b border-line px-4 lg:px-6">
        <div role="tablist" aria-label="Tipo de reporte" className="flex gap-1">
          {TABS.map((t) => (
            <button
              key={t.id}
              type="button"
              role="tab"
              aria-selected={tab === t.id}
              onClick={() => setTab(t.id)}
              className={`-mb-px border-b-2 px-3 py-3 text-sm font-medium ${
                tab === t.id ? 'border-ok text-white' : 'border-transparent text-ink-muted hover:text-white'
              }`}
            >
              {t.label}
            </button>
          ))}
        </div>

        <span className="text-sm text-ink-muted">{period.label}</span>

        <a
          href={reportCsvUrl(tab, filters, tab === 'trips' ? minKm : 0)}
          className="ml-auto inline-flex items-center gap-2 rounded-md border border-line px-3 py-1.5 text-sm hover:bg-surface-raised"
        >
          <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d="M12 3v12 M7 10l5 5 5-5 M4 21h16" />
          </svg>
          Descargar para Excel
        </a>
      </div>

      <main className="flex-1 overflow-x-auto px-4 py-4 lg:px-6">
        {tab === 'daily' && <DailyTable state={daily} manyVehicles={manyVehicles} />}
        {tab === 'trips' && (
          <TripsTable
            state={trips}
            manyVehicles={manyVehicles}
            showShort={showShort}
            onShowShortChange={setShowShort}
            onPage={goToPage}
          />
        )}
        {tab === 'stops' && <StopsTable state={stops} manyVehicles={manyVehicles} onPage={goToPage} />}
      </main>
    </div>
  )
}

// Estados comunes de las tablas: cargando (la primera vez), error o sin datos
function Status<T>({ state, isEmpty, empty }: { state: LoadState<T>; isEmpty: boolean; empty: string }) {
  if (state.status === 'loading' && state.last === undefined) return <p className="py-6 text-sm text-ink-muted">Cargando…</p>
  if (state.status === 'error') {
    return (
      <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-sm text-late">
        No se pudo cargar el reporte ({state.message}). Revisa que Laravel esté corriendo y vuelve a intentar.
      </p>
    )
  }
  if (isEmpty) return <p className="py-6 text-sm text-ink-muted">{empty}</p>
  return null
}

const th = 'px-3 py-2 text-left font-normal text-ink-muted whitespace-nowrap'
const thNum = 'px-3 py-2 text-right font-normal text-ink-muted whitespace-nowrap'
const td = 'px-3 py-2 whitespace-nowrap'
const tdNum = 'px-3 py-2 text-right tabular-nums whitespace-nowrap'

function DailyTable({ state, manyVehicles }: { state: LoadState<DailyReportRow[]>; manyVehicles: boolean }) {
  const rows = shown(state)
  if (!rows || rows.length === 0) {
    return <Status state={state} isEmpty empty="No hay viajes en este periodo. Prueba con otras fechas." />
  }
  const busy = state.status === 'loading'
  const totals = {
    trips: rows.reduce((s, r) => s + r.trips, 0),
    km: rows.reduce((s, r) => s + r.km, 0),
    driving: rows.reduce((s, r) => s + r.driving_seconds, 0),
    stopped: rows.reduce((s, r) => s + r.stopped_seconds, 0),
    maxSpeed: Math.max(...rows.map((r) => r.max_speed)),
  }

  return (
    <table className={`w-full text-sm ${busy ? 'opacity-60' : ''}`}>
      <thead className="border-b border-line">
        <tr>
          {manyVehicles && <th className={th}>Unidad</th>}
          <th className={th}>Día</th>
          <th className={thNum}>Viajes</th>
          <th className={thNum}>Km</th>
          <th className={thNum}>En ruta</th>
          <th className={thNum}>Primera salida</th>
          <th className={thNum}>Última llegada</th>
          <th className={thNum}>Jornada</th>
          <th className={thNum}>Detenida</th>
          <th className={thNum}>Vel. máx.</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-line">
        {rows.map((r) => (
          <tr key={`${r.vehicle_id}-${r.date}`} className="hover:bg-surface-raised/60">
            {manyVehicles && <td className={td}>{r.vehicle}</td>}
            <td className={td}>
              <a
                href={`#/historial/${r.vehicle_id}/${r.date}`}
                className="underline decoration-line underline-offset-4 hover:decoration-ok"
              >
                {formatDayLabel(r.date)}
              </a>
            </td>
            <td className={tdNum}>{r.trips}</td>
            <td className={tdNum}>{r.km.toFixed(1)}</td>
            <td className={tdNum}>{formatDuration(r.driving_seconds)}</td>
            <td className={tdNum}>{formatClock(r.first_start)}</td>
            <td className={tdNum}>{formatClock(r.last_end)}</td>
            <td className={tdNum}>{formatDuration(r.workday_seconds)}</td>
            <td className={tdNum}>{formatDuration(r.stopped_seconds)}</td>
            <td className={tdNum}>{r.max_speed} km/h</td>
          </tr>
        ))}
      </tbody>
      <tfoot className="border-t-2 border-line font-semibold">
        <tr>
          {manyVehicles && <td className={td} />}
          <td className={td}>
            Total, {rows.length} {rows.length === 1 ? 'día' : 'días'}
          </td>
          <td className={tdNum}>{totals.trips}</td>
          <td className={tdNum}>{totals.km.toFixed(1)}</td>
          <td className={tdNum}>{formatDuration(totals.driving)}</td>
          <td className={td} />
          <td className={td} />
          <td className={td} />
          <td className={tdNum}>{formatDuration(totals.stopped)}</td>
          <td className={tdNum}>{totals.maxSpeed} km/h</td>
        </tr>
      </tfoot>
    </table>
  )
}

function TripsTable({
  state,
  manyVehicles,
  showShort,
  onShowShortChange,
  onPage,
}: {
  state: LoadState<TripsReport>
  manyVehicles: boolean
  showShort: boolean
  onShowShortChange: (show: boolean) => void
  onPage: (page: number) => void
}) {
  const report = shown(state)
  const shortToggle = report && report.totals.short_trips > 0 && (
    <label className="mb-3 flex cursor-pointer items-center gap-2 text-sm text-ink-muted">
      <input type="checkbox" checked={showShort} onChange={(e) => onShowShortChange(e.target.checked)} className="accent-ok" />
      Mostrar movimientos de menos de {SHORT_TRIP_KM} km ({report.totals.short_trips.toLocaleString('es-MX')})
    </label>
  )

  if (!report || report.data.length === 0) {
    return (
      <>
        {shortToggle}
        <Status state={state} isEmpty empty="No hay viajes en este periodo. Prueba con otras fechas." />
      </>
    )
  }

  const rows = report.data
  const { totals, meta } = report
  const busy = state.status === 'loading'

  return (
    <>
      {shortToggle}
      <table className={`w-full text-sm ${busy ? 'opacity-60' : ''}`}>
        <thead className="border-b border-line">
          <tr>
            {manyVehicles && <th className={th}>Unidad</th>}
            <th className={th}>Día</th>
            <th className={thNum}>Salida</th>
            <th className={thNum}>Llegada</th>
            <th className={th}>Recorrido</th>
            <th className={thNum}>Km</th>
            <th className={thNum}>Duración</th>
            <th className={thNum}>Vel. máx.</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {rows.map((r) => {
            const date = cancunDateOf(r.started_at)
            return (
              <tr key={r.id} className="hover:bg-surface-raised/60">
                {manyVehicles && <td className={td}>{r.vehicle}</td>}
                <td className={td}>
                  <a
                    href={`#/historial/${r.vehicle_id}/${date}`}
                    className="underline decoration-line underline-offset-4 hover:decoration-ok"
                  >
                    {formatDayLabel(date)}
                  </a>
                </td>
                <td className={tdNum}>{formatClock(r.started_at)}</td>
                <td className={tdNum}>{formatClock(r.ended_at)}</td>
                <td className="px-3 py-2">{tripLabel(r)}</td>
                <td className={tdNum}>{r.distance_km.toFixed(1)}</td>
                <td className={tdNum}>{formatDuration(r.duration_seconds)}</td>
                <td className={tdNum}>{r.max_speed !== null ? `${r.max_speed} km/h` : ''}</td>
              </tr>
            )
          })}
        </tbody>
        <tfoot className="border-t-2 border-line font-semibold">
          <tr>
            {manyVehicles && <td className={td} />}
            <td className={td} colSpan={4}>
              Total del periodo, {totals.trips.toLocaleString('es-MX')} {totals.trips === 1 ? 'viaje' : 'viajes'}
            </td>
            <td className={tdNum}>{totals.km.toFixed(1)}</td>
            <td className={tdNum}>{formatDuration(totals.duration_seconds)}</td>
            <td className={td} />
          </tr>
        </tfoot>
      </table>
      <Pagination meta={meta} busy={busy} noun="viajes" onPage={onPage} />
    </>
  )
}

function StopsTable({
  state,
  manyVehicles,
  onPage,
}: {
  state: LoadState<StopsReport>
  manyVehicles: boolean
  onPage: (page: number) => void
}) {
  const report = shown(state)
  if (!report || report.data.length === 0) {
    return <Status state={state} isEmpty empty="No hay paradas entre viajes en este periodo." />
  }

  const rows = report.data
  const { totals, meta } = report
  const busy = state.status === 'loading'

  return (
    <>
      <table className={`w-full text-sm ${busy ? 'opacity-60' : ''}`}>
        <thead className="border-b border-line">
          <tr>
            {manyVehicles && <th className={th}>Unidad</th>}
            <th className={th}>Día</th>
            <th className={thNum}>Desde</th>
            <th className={thNum}>Hasta</th>
            <th className={thNum}>Duración</th>
            <th className={th}>Lugar</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {rows.map((r) => (
            <tr key={`${r.vehicle_id}-${r.started_at}`} className="hover:bg-surface-raised/60">
              {manyVehicles && <td className={td}>{r.vehicle}</td>}
              <td className={td}>{formatDayLabel(cancunDateOf(r.started_at))}</td>
              <td className={tdNum}>{formatClock(r.started_at)}</td>
              <td className={tdNum}>{formatClock(r.ended_at)}</td>
              <td className={tdNum}>{formatDuration(r.duration_seconds)}</td>
              <td className="px-3 py-2">
                {r.place?.replace(/\s+lobby$/i, '') ?? <span className="text-ink-muted">{r.zone ?? 'Sin lugar conocido'}</span>}
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot className="border-t-2 border-line font-semibold">
          <tr>
            {manyVehicles && <td className={td} />}
            <td className={td} colSpan={3}>
              Total del periodo, {totals.stops.toLocaleString('es-MX')} {totals.stops === 1 ? 'parada' : 'paradas'}
            </td>
            <td className={tdNum}>{formatDuration(totals.duration_seconds)}</td>
            <td className={td} />
          </tr>
        </tfoot>
      </table>
      <Pagination meta={meta} busy={busy} noun="paradas" onPage={onPage} />
    </>
  )
}