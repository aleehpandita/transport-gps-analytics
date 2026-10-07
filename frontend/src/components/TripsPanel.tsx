import type { Trip } from '../types/api'
import { formatKm, formatTime } from '../lib/format'

interface TripsPanelProps {
  trips: Trip[]
  loading: boolean
}

// Duración legible: "15 min", o "1 h 05 min" a partir de una hora
function formatDuration(seconds: number): string {
  const totalMinutes = Math.round(seconds / 60)
  if (totalMinutes < 60) return `${totalMinutes} min`

  const hours = Math.floor(totalMinutes / 60)
  const minutes = totalMinutes % 60
  return `${hours} h ${String(minutes).padStart(2, '0')} min`
}

export function TripsPanel({ trips, loading }: TripsPanelProps) {
  if (loading && trips.length === 0) {
    return <p className="px-5 py-6 text-sm text-ink-muted">Cargando viajes…</p>
  }

  if (trips.length === 0) {
    return <p className="px-5 py-6 text-sm text-ink-muted">Todavía no hay viajes registrados hoy.</p>
  }

  return (
    <ul className="max-h-[420px] divide-y divide-line overflow-auto">
      {trips.map((trip) => (
        <li key={trip.id} className="px-5 py-3">
          <div className="flex items-baseline justify-between gap-3">
            <p className="font-mono text-sm tabular-nums">
              {formatTime(trip.started_at)} → {formatTime(trip.ended_at)}
            </p>
            <p className="text-sm whitespace-nowrap tabular-nums">
              {formatDuration(trip.duration_seconds)}
              <span className="text-ink-muted">, {formatKm(trip.distance_km)}</span>
            </p>
          </div>
          <p className="mt-1 text-sm text-ink-muted">
            {trip.vehicle ?? `Unidad ${trip.vehicle_id}`}
            {', '}
            {trip.origin_zone ?? 'Sin zona'} → {trip.destination_zone ?? 'Sin zona'}
          </p>
        </li>
      ))}
    </ul>
  )
}