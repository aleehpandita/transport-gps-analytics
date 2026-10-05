import type { Vehicle } from '../types/api'
import { formatKm, formatSpeed, formatTime } from '../lib/format'
import { STATUS_DOT, STATUS_LABEL, STATUS_ORDER, vehicleStatus } from '../lib/vehicleStatus'

interface FleetTableProps {
  vehicles: Vehicle[]
  loading: boolean
}

export function FleetTable({ vehicles, loading }: FleetTableProps) {
  if (loading && vehicles.length === 0) {
    return <p className="px-5 py-6 text-sm text-ink-muted">Cargando unidades…</p>
  }

  if (vehicles.length === 0) {
    return (
      <p className="px-5 py-6 text-sm text-ink-muted">
        No hay unidades registradas. Ejecuta <code className="font-mono">traccar:sync-positions</code> para traerlas.
      </p>
    )
  }

  const rows = [...vehicles]
    .map((v) => ({ v, status: vehicleStatus(v) }))
    .sort((a, b) => STATUS_ORDER[a.status] - STATUS_ORDER[b.status] || a.v.name.localeCompare(b.v.name))

  return (
    <div className="max-h-[420px] overflow-auto">
      <table className="w-full text-sm">
        <thead className="sticky top-0 bg-surface text-left font-mono text-[11px] tracking-[0.14em] text-ink-muted">
          <tr>
            <th className="px-5 py-2 font-normal">Unidad</th>
            <th className="px-5 py-2 font-normal">Estado</th>
            <th className="px-5 py-2 text-right font-normal">Velocidad</th>
            <th className="px-5 py-2 text-right font-normal">Hoy</th>
            <th className="px-5 py-2 text-right font-normal">Última señal</th>
          </tr>
        </thead>
        <tbody>
          {rows.map(({ v, status }) => (
            <tr key={v.id} className="border-t border-line hover:bg-surface-raised">
              <td className="px-5 py-3 font-medium">{v.name}</td>
              <td className="px-5 py-3">
                <span className="flex items-center gap-2">
                  <span className={`h-2 w-2 shrink-0 rounded-full ${STATUS_DOT[status]}`} aria-hidden="true" />
                  {STATUS_LABEL[status]}
                </span>
              </td>
              <td className="px-5 py-3 text-right tabular-nums">
                {v.latest_position ? formatSpeed(v.latest_position.speed) : '—'}
              </td>
              <td className="px-5 py-3 text-right tabular-nums">{formatKm(v.distance_today_km ?? 0)}</td>
              <td className="px-5 py-3 text-right font-mono text-xs text-ink-muted">
                {v.latest_position ? formatTime(v.latest_position.device_time) : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}