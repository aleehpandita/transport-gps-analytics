import { useState } from 'react'
import type { Trip } from '../types/api'
import { formatKm, formatTime } from '../lib/format'
import { tripLabel } from '../lib/tripLabel'

// Movimientos cortos (acomodarse en el estacionamiento del aeropuerto, por ejemplo): se
// ocultan de entrada, igual que en el historial
const SHORT_TRIP_KM = 2

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
  const [showShort, setShowShort] = useState(false)

  if (loading && trips.length === 0) {
    return <p className="px-5 py-6 text-sm text-ink-muted">Cargando viajes…</p>
  }

  if (trips.length === 0) {
    return <p className="px-5 py-6 text-sm text-ink-muted">Todavía no hay viajes registrados hoy.</p>
  }

  const visible = showShort ? trips : trips.filter((t) => t.distance_km >= SHORT_TRIP_KM)
  const hidden = trips.length - visible.length
  // Con una sola unidad, repetir su nombre en cada viaje no aporta
  const manyVehicles = new Set(trips.map((t) => t.vehicle_id)).size > 1

  return (
    <div>
      <ul className="max-h-[420px] divide-y divide-line overflow-auto">
        {visible.map((trip) => (
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
              {manyVehicles && <span className="text-white/80">{trip.vehicle ?? `Unidad ${trip.vehicle_id}`}: </span>}
              {tripLabel(trip)}
            </p>
          </li>
        ))}
      </ul>

      {(hidden > 0 || showShort) && (
        <label className="flex cursor-pointer items-center gap-2 border-t border-line px-5 py-3 text-sm text-ink-muted">
          <input type="checkbox" checked={showShort} onChange={(e) => setShowShort(e.target.checked)} className="accent-ok" />
          Mostrar movimientos de menos de {SHORT_TRIP_KM} km{hidden > 0 ? ` (${hidden})` : ''}
        </label>
      )}
    </div>
  )
}