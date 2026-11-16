import type { ReactNode } from 'react'
import { shown, type LoadState } from '../lib/loadState'
import { cancunDateOf, formatClock, formatDayLabel, formatDuration } from '../lib/time'
import type { AlertsReport, DistanceReport, GeofenceVisitsReport } from '../types/api'
import { Pagination } from './Pagination'
import { ReportStatus, td, tdNum, th, thNum } from './reportTable'

// Reportes de la empresa (EkMira BASE): entradas y salidas de geocercas, distancia por
// unidad y día, y alertas por unidad y tipo. En celular cada tabla se desliza de lado
// con la primera columna fija, y arriba van los totales en tarjetas.

// Primera columna fija al deslizar la tabla en celular
const stickyTh = `${th} sticky left-0 z-10 bg-base`
const stickyTd = `${td} sticky left-0 z-10 bg-base`

function Cards({ items }: { items: { label: string; value: string }[] }) {
  return (
    <dl className="mb-4 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:gap-3">
      {items.map((item) => (
        <div key={item.label} className="rounded-md border border-line px-3 py-2">
          <dt className="text-xs text-ink-muted">{item.label}</dt>
          <dd className="text-lg font-semibold tabular-nums">{item.value}</dd>
        </div>
      ))}
    </dl>
  )
}

function Scroll({ children, busy }: { children: ReactNode; busy: boolean }) {
  return <div className={`-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0 ${busy ? 'opacity-60' : ''}`}>{children}</div>
}

const km = (value: number) => value.toLocaleString('es-MX', { minimumFractionDigits: 1, maximumFractionDigits: 1 })

// ------------------------------------------------------------------ Entradas y salidas

export function GeofenceVisitsTable({
  state,
  manyVehicles,
  onPage,
}: {
  state: LoadState<GeofenceVisitsReport>
  manyVehicles: boolean
  onPage: (page: number) => void
}) {
  const report = shown(state)
  if (!report || report.data.length === 0) {
    const noFences = report && report.geofences.length === 0
    return (
      <ReportStatus
        state={state}
        isEmpty
        empty={
          noFences
            ? 'Todavía no hay geocercas. Dibújalas en Alertas → Geocercas y aquí verás cada entrada y salida.'
            : 'No hubo entradas ni salidas de geocercas en este periodo.'
        }
      />
    )
  }

  const busy = state.status === 'loading'
  const { summary, totals, meta } = report

  return (
    <>
      <Cards
        items={[
          { label: 'Visitas', value: totals.visits.toLocaleString('es-MX') },
          { label: 'Tiempo dentro', value: formatDuration(totals.duration_seconds) },
        ]}
      />

      <h2 className="mb-2 text-sm font-semibold">Resumen por unidad y geocerca</h2>
      <Scroll busy={busy}>
        <table className="mb-6 w-full text-sm">
          <thead className="border-b border-line">
            <tr>
              <th className={stickyTh}>Unidad</th>
              <th className={th}>Geocerca</th>
              <th className={thNum}>Visitas</th>
              <th className={thNum}>Tiempo dentro</th>
              <th className={thNum}>Promedio</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {summary.map((r) => (
              <tr key={`${r.vehicle_id}-${r.geofence_id}`}>
                <td className={stickyTd}>{r.vehicle}</td>
                <td className={td}>{r.geofence}</td>
                <td className={tdNum}>{r.visits}</td>
                <td className={tdNum}>{formatDuration(r.duration_seconds)}</td>
                <td className={tdNum}>{formatDuration(r.average_seconds)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Scroll>

      <h2 className="mb-2 text-sm font-semibold">Entradas y salidas</h2>
      <Scroll busy={busy}>
        <table className="w-full text-sm">
          <thead className="border-b border-line">
            <tr>
              {manyVehicles && <th className={stickyTh}>Unidad</th>}
              <th className={manyVehicles ? th : stickyTh}>Geocerca</th>
              <th className={th}>Entrada</th>
              <th className={th}>Salida</th>
              <th className={thNum}>Tiempo dentro</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {report.data.map((r) => (
              <tr key={r.id} className="hover:bg-surface-raised/60">
                {manyVehicles && <td className={stickyTd}>{r.vehicle}</td>}
                <td className={manyVehicles ? td : stickyTd}>
                  <span className="mr-2 inline-block size-2 rounded-full" style={{ background: r.color ?? '#5b8def' }} aria-hidden="true" />
                  {r.geofence}
                </td>
                <td className={td}>
                  {r.started_inside ? (
                    <span className="text-ink-muted" title="Ya estaba dentro cuando empezó el registro">
                      Ya estaba dentro
                    </span>
                  ) : (
                    <DateTime iso={r.entered_at} />
                  )}
                </td>
                <td className={td}>{r.exited_at ? <DateTime iso={r.exited_at} /> : <span className="text-ok">Sigue dentro</span>}</td>
                <td className={tdNum}>{formatDuration(r.duration_seconds)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Scroll>
      <Pagination meta={meta} busy={busy} noun="visitas" onPage={onPage} />
    </>
  )
}

function DateTime({ iso }: { iso: string }) {
  return (
    <>
      {formatDayLabel(cancunDateOf(iso))} <span className="tabular-nums">{formatClock(iso)}</span>
    </>
  )
}

// ------------------------------------------------------------------ Distancia

export function DistanceTable({ state }: { state: LoadState<DistanceReport> }) {
  const report = shown(state)
  if (!report || report.data.length === 0) {
    return <ReportStatus state={state} isEmpty empty="No hay unidades en esta empresa." />
  }

  const busy = state.status === 'loading'
  const { days, data, totals } = report
  // Más reciente primero en la tabla por día
  const daysDesc = [...days].reverse()

  return (
    <>
      <Cards
        items={[
          { label: 'Km de la flota', value: km(totals.km) },
          { label: 'Viajes', value: totals.trips.toLocaleString('es-MX') },
          { label: 'Unidades', value: String(data.length) },
          { label: 'Promedio por unidad', value: `${km(totals.km / data.length)} km` },
        ]}
      />

      <h2 className="mb-2 text-sm font-semibold">Por unidad</h2>
      <Scroll busy={busy}>
        <table className="mb-6 w-full text-sm">
          <thead className="border-b border-line">
            <tr>
              <th className={stickyTh}>Unidad</th>
              <th className={thNum}>Km</th>
              <th className={thNum}>Viajes</th>
              <th className={thNum}>Días con movimiento</th>
              <th className={thNum}>Km por día con movimiento</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {data.map((r) => (
              <tr key={r.vehicle_id}>
                <td className={stickyTd}>{r.vehicle}</td>
                <td className={tdNum}>{km(r.total_km)}</td>
                <td className={tdNum}>{r.total_trips}</td>
                <td className={tdNum}>
                  {r.active_days} de {days.length}
                </td>
                <td className={tdNum}>{km(r.average_km)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Scroll>

      <h2 className="mb-2 text-sm font-semibold">Km por día</h2>
      <Scroll busy={busy}>
        <table className="w-full text-sm">
          <thead className="border-b border-line">
            <tr>
              <th className={stickyTh}>Día</th>
              {data.map((r) => (
                <th key={r.vehicle_id} className={thNum}>
                  {r.vehicle}
                </th>
              ))}
              {data.length > 1 && <th className={thNum}>Flota</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {daysDesc.map((day) => (
              <tr key={day} className="hover:bg-surface-raised/60">
                <td className={stickyTd}>{formatDayLabel(day)}</td>
                {data.map((r) => (
                  <td key={r.vehicle_id} className={`${tdNum} ${r.km[day] === 0 ? 'text-ink-muted' : ''}`}>
                    {r.km[day] === 0 ? (
                      '0'
                    ) : (
                      <a href={`#/historial/${r.vehicle_id}/${day}`} className="underline decoration-line underline-offset-4 hover:decoration-ok">
                        {km(r.km[day])}
                      </a>
                    )}
                  </td>
                ))}
                {data.length > 1 && (
                  <td className={`${tdNum} ${totals.by_day[day] ? 'font-semibold' : 'text-ink-muted'}`}>
                    {totals.by_day[day] ? km(totals.by_day[day]) : '0'}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
          <tfoot className="border-t-2 border-line font-semibold">
            <tr>
              <td className={stickyTd}>Total</td>
              {data.map((r) => (
                <td key={r.vehicle_id} className={tdNum}>
                  {km(r.total_km)}
                </td>
              ))}
              {data.length > 1 && <td className={tdNum}>{km(totals.km)}</td>}
            </tr>
          </tfoot>
        </table>
      </Scroll>
    </>
  )
}

// ------------------------------------------------------------------ Alertas

export function AlertsReportTable({
  state,
  manyVehicles,
  onPage,
}: {
  state: LoadState<AlertsReport>
  manyVehicles: boolean
  onPage: (page: number) => void
}) {
  const report = shown(state)
  if (!report || report.data.length === 0) {
    return <ReportStatus state={state} isEmpty empty="No hubo alertas en este periodo." />
  }

  const busy = state.status === 'loading'
  const { types, summary, totals, meta } = report

  return (
    <>
      <Cards
        items={[
          { label: 'Alertas', value: totals.total.toLocaleString('es-MX') },
          ...types.map((t) => ({ label: t.label, value: String(totals.by_type[t.type] ?? 0) })),
        ]}
      />

      <h2 className="mb-2 text-sm font-semibold">Por unidad y tipo</h2>
      <Scroll busy={busy}>
        <table className="mb-6 w-full text-sm">
          <thead className="border-b border-line">
            <tr>
              <th className={stickyTh}>Unidad</th>
              {types.map((t) => (
                <th key={t.type} className={thNum}>
                  {t.label}
                </th>
              ))}
              <th className={thNum}>Total</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {summary.map((r) => (
              <tr key={r.vehicle_id}>
                <td className={stickyTd}>{r.vehicle}</td>
                {types.map((t) => (
                  <td key={t.type} className={`${tdNum} ${(r.counts[t.type] ?? 0) === 0 ? 'text-ink-muted' : ''}`}>
                    {r.counts[t.type] ?? 0}
                  </td>
                ))}
                <td className={`${tdNum} font-semibold`}>{r.total}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Scroll>

      <h2 className="mb-2 text-sm font-semibold">Detalle</h2>
      <Scroll busy={busy}>
        <table className="w-full text-sm">
          <thead className="border-b border-line">
            <tr>
              <th className={stickyTh}>Cuándo</th>
              {manyVehicles && <th className={th}>Unidad</th>}
              <th className={th}>Tipo</th>
              <th className={th}>Aviso</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {report.data.map((a) => (
              <tr key={a.id} className="hover:bg-surface-raised/60">
                <td className={stickyTd}>
                  <DateTime iso={a.triggered_at} />
                </td>
                {manyVehicles && <td className={td}>{a.vehicle}</td>}
                <td className={td}>{a.type_label}</td>
                <td className="min-w-64 px-3 py-2">
                  <a
                    href={`https://www.google.com/maps?q=${a.latitude},${a.longitude}`}
                    target="_blank"
                    rel="noreferrer"
                    className="underline decoration-line underline-offset-4 hover:decoration-ok"
                  >
                    {a.message}
                  </a>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Scroll>
      <Pagination meta={meta} busy={busy} noun="alertas" onPage={onPage} />
    </>
  )
}
