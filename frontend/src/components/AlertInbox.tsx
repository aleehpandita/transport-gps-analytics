import { useCallback, useEffect, useState } from 'react'
import { ALERT_TYPES, alertTypeInfo } from '../lib/alerts'
import { fetchAlerts, markAlertRead, markAllAlertsRead, type AlertFilters } from '../lib/apiClient'
import { formatDateTime } from '../lib/format'
import { cancunDateOf } from '../lib/time'
import type { AlertRow, AlertsPage, AlertType, Vehicle } from '../types/api'
import { Pagination } from './Pagination'
import { Panel } from './Panel'

// Bandeja de alertas: lo más nuevo arriba, sin leer resaltado. Se actualiza sola cada minuto.

const REFRESH_MS = 60_000

const select = 'rounded-md border border-line bg-surface-raised px-3 py-1.5'
const button = 'rounded-md border border-line px-2.5 py-1 text-ink-muted hover:bg-surface-raised disabled:opacity-60'

export function AlertInbox({ vehicles }: { vehicles: Vehicle[] }) {
  const [filters, setFilters] = useState<AlertFilters>({ status: 'unread', type: null, vehicleId: null })
  const [page, setPage] = useState(1)
  const [data, setData] = useState<AlertsPage | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [tick, setTick] = useState(0)

  const reload = useCallback(() => setTick((n) => n + 1), [])

  useEffect(() => {
    const id = window.setInterval(reload, REFRESH_MS)
    return () => window.clearInterval(id)
  }, [reload])

  useEffect(() => {
    const controller = new AbortController()
    fetchAlerts(filters, page, controller.signal)
      .then((value) => {
        setData(value)
        setError(null)
      })
      .catch((e: unknown) => {
        if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'No se pudo cargar.')
      })
    return () => controller.abort()
  }, [filters, page, tick])

  function changeFilters(next: Partial<AlertFilters>) {
    setFilters((f) => ({ ...f, ...next }))
    setPage(1)
  }

  async function act(task: () => Promise<unknown>) {
    setBusy(true)
    try {
      await task()
      reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar.')
    } finally {
      setBusy(false)
    }
  }

  const unread = data?.meta.unread ?? 0

  return (
    <Panel index={1} title="Bandeja de alertas" aside={data ? `${unread} sin leer` : undefined}>
      <div className="flex flex-col gap-4 p-4 text-sm">
        <div className="flex flex-wrap items-end gap-3">
          <div role="group" aria-label="Mostrar" className="flex rounded-md border border-line">
            {(['unread', 'all'] as const).map((status) => (
              <button
                key={status}
                type="button"
                aria-pressed={filters.status === status}
                onClick={() => changeFilters({ status })}
                className={`px-3 py-1.5 ${filters.status === status ? 'bg-surface-raised font-medium' : 'text-ink-muted hover:text-ink'}`}
              >
                {status === 'unread' ? 'Sin leer' : 'Todas'}
              </button>
            ))}
          </div>
          <label className="flex flex-col gap-1">
            <span className="text-ink-muted">Tipo</span>
            <select
              value={filters.type ?? ''}
              onChange={(e) => changeFilters({ type: (e.target.value || null) as AlertType | null })}
              className={select}
            >
              <option value="">Todos</option>
              {ALERT_TYPES.map((t) => (
                <option key={t.type} value={t.type}>
                  {t.label}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1">
            <span className="text-ink-muted">Unidad</span>
            <select
              value={filters.vehicleId ?? ''}
              onChange={(e) => changeFilters({ vehicleId: e.target.value ? Number(e.target.value) : null })}
              className={select}
            >
              <option value="">Todas</option>
              {vehicles.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </select>
          </label>
          {unread > 0 && (
            <button type="button" disabled={busy} onClick={() => act(markAllAlertsRead)} className={`${button} ml-auto`}>
              Marcar todas como leídas
            </button>
          )}
        </div>

        {error && (
          <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-late">
            {error}
          </p>
        )}

        {!data && !error && <p className="text-ink-muted">Cargando…</p>}

        {data && data.data.length === 0 && (
          <p className="rounded-md border border-line px-4 py-6 text-center text-ink-muted">
            {filters.status === 'unread' ? 'No hay alertas sin leer.' : 'No hay alertas con estos filtros.'}
            <br />
            Si aún no configuras ninguna, empieza en{' '}
            <a href="#/alertas/configurar" className="text-ok underline">
              Configurar alertas
            </a>
            .
          </p>
        )}

        {data && data.data.length > 0 && (
          <ul className="divide-y divide-line rounded-md border border-line">
            {data.data.map((alert) => (
              <AlertItem key={alert.id} alert={alert} busy={busy} onRead={() => act(() => markAlertRead(alert.id))} />
            ))}
          </ul>
        )}

        {data && <Pagination meta={data.meta} busy={busy} noun="alertas" onPage={setPage} />}
      </div>
    </Panel>
  )
}

function AlertItem({ alert, busy, onRead }: { alert: AlertRow; busy: boolean; onRead: () => void }) {
  const info = alertTypeInfo(alert.type)
  const unread = alert.read_at === null
  const day = cancunDateOf(alert.triggered_at)
  const mapUrl = `https://www.google.com/maps?q=${alert.latitude},${alert.longitude}`

  return (
    <li className={`flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3 ${unread ? 'bg-surface-raised/60' : ''}`}>
      <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${info.tone}`} aria-hidden="true" />
      <div className="min-w-[14rem] flex-1">
        <p className={unread ? 'font-medium' : ''}>{alert.message}</p>
        <p className="mt-0.5 text-ink-muted">
          {info.label} · {formatDateTime(alert.triggered_at)}
          {alert.rule && <> · {alert.rule.name}</>}
          {alert.email_status === 'sent' && <> · enviada por correo</>}
          {alert.email_status === 'failed' && <span className="text-late"> · el correo no salió</span>}
        </p>
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {alert.vehicle && (
          <a href={`#/historial/${alert.vehicle.id}/${day}`} className={button}>
            Ver recorrido
          </a>
        )}
        <a href={mapUrl} target="_blank" rel="noreferrer" className={button}>
          Mapa
        </a>
        {unread && (
          <button type="button" disabled={busy} onClick={onRead} className={button}>
            Leída
          </button>
        )}
      </div>
    </li>
  )
}
