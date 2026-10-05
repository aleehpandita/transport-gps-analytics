import type { Vehicle } from '../types/api'
import { formatKm, formatTime } from '../lib/format'
import { vehicleStatus } from '../lib/vehicleStatus'

interface HeaderProps {
  vehicles: Vehicle[]
  updatedAt: Date | null
  error: string | null
}

export function Header({ vehicles, updatedAt, error }: HeaderProps) {
  const withSignal = vehicles.filter((v) => {
    const s = vehicleStatus(v)
    return s !== 'stale' && s !== 'unknown'
  }).length
  const moving = vehicles.filter((v) => vehicleStatus(v) === 'moving').length
  const kmToday = vehicles.reduce((sum, v) => sum + (v.distance_today_km ?? 0), 0)

  return (
    <header className="flex flex-wrap items-end justify-between gap-6 border-b border-line px-6 py-5">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Feraltar</h1>
        <p className="text-sm text-ink-muted">Operación en vivo, Cancún y Riviera Maya</p>
      </div>

      <dl className="flex flex-wrap gap-8">
        <Stat label="En ruta" value={`${moving}`} />
        <Stat label="Con señal" value={`${withSignal} / ${vehicles.length}`} />
        <Stat label="Recorrido hoy" value={formatKm(kmToday)} />
        <div>
          <dt className="font-mono text-[11px] tracking-[0.14em] text-ink-muted">Actualizado</dt>
          <dd className="mt-1 flex items-center gap-2 font-mono text-sm">
            <span
              className={`h-2 w-2 rounded-full ${error ? 'bg-late' : 'bg-ok'}`}
              aria-hidden="true"
            />
            {updatedAt ? formatTime(updatedAt) : '—'}
          </dd>
        </div>
      </dl>
    </header>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="font-mono text-[11px] tracking-[0.14em] text-ink-muted">{label}</dt>
      <dd className="mt-1 text-lg font-medium tabular-nums">{value}</dd>
    </div>
  )
}