import type { ScheduledService, Vehicle } from '../types/api'
import { formatDateTime } from '../lib/format'

interface ArrivalsPanelProps {
  services: ScheduledService[]
  vehicles: Vehicle[]
  loading: boolean
}

// Se muestran servicios desde 1 h atrás (pueden seguir en curso) en adelante
const LOOKBACK_MS = 60 * 60 * 1000
const MAX_ITEMS = 12

export function ArrivalsPanel({ services, vehicles, loading }: ArrivalsPanelProps) {
  if (loading && services.length === 0) {
    return <p className="px-5 py-6 text-sm text-ink-muted">Cargando servicios…</p>
  }

  const vehicleName = new Map(vehicles.map((v) => [v.id, v.name]))
  const since = Date.now() - LOOKBACK_MS

  const upcoming = services
    .filter((s) => new Date(s.scheduled_at).getTime() >= since)
    .sort((a, b) => new Date(a.scheduled_at).getTime() - new Date(b.scheduled_at).getTime())
    .slice(0, MAX_ITEMS)

  if (upcoming.length === 0) {
    return (
      <p className="px-5 py-6 text-sm text-ink-muted">
        No hay servicios programados para las próximas horas.
      </p>
    )
  }

  return (
    <ul className="max-h-[calc(100vh-14rem)] divide-y divide-line overflow-auto">
      {upcoming.map((s) => (
        <li key={s.id} className="px-5 py-4">
          <div className="flex items-baseline justify-between gap-3">
            <p className="font-medium">{s.destination_zone ?? 'Destino sin zona'}</p>
            <span className="rounded border border-line px-2 py-0.5 font-mono text-[11px] text-ink-muted">
              ETA pendiente
            </span>
          </div>
          <p className="mt-1 text-sm text-ink-muted">
            {s.vehicle ?? vehicleName.get(s.vehicle_id) ?? `Unidad ${s.vehicle_id}`}
            {', '}
            {formatDateTime(s.scheduled_at)}
          </p>
          {s.notes && <p className="mt-2 text-sm text-ink-muted">{s.notes}</p>}
        </li>
      ))}
    </ul>
  )
}