import { useEffect, useMemo, useState } from 'react'
import { fetchVehicleRoute, fetchVehicles } from '../lib/apiClient'
import { usePlayback } from '../hooks/usePlayback'
import { cancunToday, formatClock, formatDayLabel, formatDuration, shiftDate, toTimestamp } from '../lib/time'
import { tripLabel } from '../lib/tripLabel'
import type { RouteDay, RoutePoint, User, Vehicle } from '../types/api'
import { DayTimeline } from './DayTimeline'
import { NavLinks } from './NavLinks'
import { RouteMap, SPEED_BANDS } from './RouteMap'

// Movimientos cortos (acomodarse en el estacionamiento del aeropuerto, por ejemplo):
// se ocultan de la lista de entrada, pero siguen en el mapa y en la cinta
const SHORT_TRIP_KM = 2

// Referencia estable para "sin datos", así la reproducción no se reinicia en cada render
const NO_POINTS: RoutePoint[] = []

interface HistoryViewProps {
  user: User
  initialVehicleId: number | null
  initialDate: string | null
  onLogout: () => void
}

type RouteState =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; route: RouteDay }

export function HistoryView({ user, initialVehicleId, initialDate, onLogout }: HistoryViewProps) {
  const today = cancunToday()

  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [vehicleId, setVehicleId] = useState<number | null>(initialVehicleId)
  const [date, setDate] = useState(initialDate ?? today)
  const [state, setState] = useState<RouteState>({ status: 'loading' })
  const [selectedTripId, setSelectedTripId] = useState<number | null>(null)
  const [showShort, setShowShort] = useState(false)
  const [follow, setFollow] = useState(false)

  // Unidades que el usuario puede ver (un cliente solo las suyas)
  useEffect(() => {
    const controller = new AbortController()
    fetchVehicles(controller.signal)
      .then((list) => {
        setVehicles(list)
        setVehicleId((current) => current ?? list[0]?.id ?? null)
      })
      .catch(() => {
        // Si falla, el error del recorrido ya lo muestra
      })
    return () => controller.abort()
  }, [])

  // El recorrido del día elegido
  useEffect(() => {
    if (vehicleId === null) return

    const controller = new AbortController()
    setState({ status: 'loading' })
    setSelectedTripId(null)

    fetchVehicleRoute(vehicleId, date, controller.signal)
      .then((route) => setState({ status: 'ready', route }))
      .catch((e: unknown) => {
        if (controller.signal.aborted) return
        setState({ status: 'error', message: e instanceof Error ? e.message : 'Error desconocido' })
      })

    return () => controller.abort()
  }, [vehicleId, date])

  // La dirección refleja lo que se ve, para compartir el link o recargar sin perderlo
  useEffect(() => {
    if (vehicleId !== null) window.history.replaceState(null, '', `#/historial/${vehicleId}/${date}`)
  }, [vehicleId, date])

  const route = state.status === 'ready' ? state.route : null
  const points = route?.points ?? NO_POINTS
  const trips = useMemo(() => route?.trips ?? [], [route])
  const playback = usePlayback(points)

  const visibleTrips = showShort ? trips : trips.filter((t) => t.distance_km >= SHORT_TRIP_KM)
  const hiddenCount = trips.length - visibleTrips.length

  // El viaje que se está reproduciendo, para marcarlo en la lista
  const playingTripId = trips.find(
    (t) => playback.time >= toTimestamp(t.started_at) && playback.time <= toTimestamp(t.ended_at),
  )?.id

  const summary = useMemo(() => {
    const real = trips.filter((t) => t.distance_km >= SHORT_TRIP_KM)
    return {
      trips: real.length,
      km: trips.reduce((sum, t) => sum + t.distance_km, 0),
      seconds: trips.reduce((sum, t) => sum + t.duration_seconds, 0),
      firstStart: trips[0]?.started_at ?? null,
      lastEnd: trips[trips.length - 1]?.ended_at ?? null,
    }
  }, [trips])

  function selectTrip(tripId: number) {
    const trip = trips.find((t) => t.id === tripId)
    if (!trip) return
    const deselect = selectedTripId === tripId
    setSelectedTripId(deselect ? null : tripId)
    playback.setPlaying(false)
    if (!deselect) playback.seek(toTimestamp(trip.started_at))
  }

  // Teclado: espacio reproduce o pausa; flechas mueven un minuto
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement
      if (['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(target.tagName)) return
      if (e.code === 'Space') {
        e.preventDefault()
        playback.toggle()
      } else if (e.code === 'ArrowRight') {
        playback.seek(playback.time + 60)
      } else if (e.code === 'ArrowLeft') {
        playback.seek(playback.time - 60)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [playback])

  const vehicleName = vehicles.find((v) => v.id === vehicleId)?.name ?? 'la unidad'

  return (
    <div className="flex min-h-screen flex-col lg:h-screen lg:overflow-hidden">
      <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 lg:px-6">
        <h1 className="text-lg font-semibold">Historial</h1>

        <select
          value={vehicleId ?? ''}
          onChange={(e) => setVehicleId(Number(e.target.value))}
          aria-label="Unidad"
          className="rounded-md border border-line bg-surface-raised px-3 py-1.5"
        >
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.name}
            </option>
          ))}
        </select>

        <div className="flex items-center gap-1">
          <button
            type="button"
            onClick={() => setDate(shiftDate(date, -1))}
            aria-label="Día anterior"
            className="rounded-md px-2 py-1.5 hover:bg-surface-raised"
          >
            ‹
          </button>
          <label className="relative">
            <span className="rounded-md px-2 py-1.5 font-medium">{date === today ? 'Hoy' : formatDayLabel(date)}</span>
            <input
              type="date"
              value={date}
              max={today}
              onChange={(e) => e.target.value && setDate(e.target.value)}
              aria-label="Día"
              className="absolute inset-0 cursor-pointer opacity-0"
            />
          </label>
          <button
            type="button"
            onClick={() => setDate(shiftDate(date, 1))}
            disabled={date >= today}
            aria-label="Día siguiente"
            className="rounded-md px-2 py-1.5 hover:bg-surface-raised disabled:opacity-30"
          >
            ›
          </button>
        </div>

        <div className="ml-auto flex flex-wrap items-center gap-3 text-sm text-ink-muted">
          <NavLinks current="history" />
          <span>{user.name}</span>
          <button type="button" onClick={onLogout} className="rounded-md border border-line px-3 py-1 hover:bg-surface-raised">
            Salir
          </button>
        </div>
      </header>

      <div className="grid flex-1 lg:min-h-0 lg:grid-cols-[340px_minmax(0,1fr)]">
        <aside className="flex max-h-[50vh] min-h-0 flex-col border-b border-line lg:max-h-none lg:border-r lg:border-b-0">
          {route && trips.length > 0 && (
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 border-b border-line px-4 py-4 text-sm lg:px-5">
              <div>
                <dt className="text-ink-muted">Viajes</dt>
                <dd className="text-xl font-semibold tabular-nums">{summary.trips}</dd>
              </div>
              <div>
                <dt className="text-ink-muted">Recorrido</dt>
                <dd className="text-xl font-semibold tabular-nums">{Math.round(summary.km)} km</dd>
              </div>
              <div>
                <dt className="text-ink-muted">En ruta</dt>
                <dd className="font-medium tabular-nums">{formatDuration(summary.seconds)}</dd>
              </div>
              <div>
                <dt className="text-ink-muted">Primera salida y última llegada</dt>
                <dd className="font-medium tabular-nums">
                  {summary.firstStart && formatClock(summary.firstStart)} a {summary.lastEnd && formatClock(summary.lastEnd)}
                </dd>
              </div>
            </dl>
          )}

          <div className="min-h-0 flex-1 overflow-auto">
            {state.status === 'loading' && <p className="px-5 py-6 text-sm text-ink-muted">Cargando el recorrido…</p>}

            {state.status === 'error' && (
              <p role="alert" className="m-4 rounded-md border border-late/40 bg-late/10 px-3 py-2 text-sm text-late">
                No se pudo cargar el recorrido ({state.message}). Revisa que Laravel esté corriendo y vuelve a intentar.
              </p>
            )}

            {route && points.length === 0 && (
              <div className="px-5 py-6 text-sm">
                <p>{vehicleName} no tiene posiciones el {formatDayLabel(date)}.</p>
                <button
                  type="button"
                  onClick={() => setDate(shiftDate(date, -1))}
                  className="mt-3 rounded-md border border-line px-3 py-1.5 hover:bg-surface-raised"
                >
                  Ver el día anterior
                </button>
              </div>
            )}

            {route && points.length > 0 && trips.length === 0 && (
              <p className="px-5 py-6 text-sm text-ink-muted">
                Hay posiciones pero ningún viaje armado todavía. El Trip Builder corre cada 10 minutos.
              </p>
            )}

            {visibleTrips.length > 0 && (
              <ol>
                {visibleTrips.map((trip) => {
                  const selected = trip.id === selectedTripId
                  const playingThis = trip.id === playingTripId

                  return (
                    <li key={trip.id}>
                      <button
                        type="button"
                        onClick={() => selectTrip(trip.id)}
                        aria-pressed={selected}
                        className={`relative w-full border-b border-line px-4 py-3 text-left lg:px-5 ${
                          selected ? 'bg-surface-raised' : 'hover:bg-surface-raised/60'
                        }`}
                      >
                        {(selected || playingThis) && (
                          <span className="absolute inset-y-0 left-0 w-1 bg-ok" aria-hidden="true" />
                        )}
                        <div className="flex items-baseline justify-between gap-3">
                          <span className="font-medium tabular-nums">
                            {formatClock(trip.started_at)} a {formatClock(trip.ended_at)}
                          </span>
                          <span className="text-xs text-ink-muted tabular-nums">
                            {trip.distance_km.toFixed(1)} km, {formatDuration(trip.duration_seconds)}
                          </span>
                        </div>
                        <div className="mt-0.5 text-sm text-ink-muted">{tripLabel(trip)}</div>
                      </button>
                    </li>
                  )
                })}
              </ol>
            )}

            {hiddenCount > 0 || showShort ? (
              <label className="flex cursor-pointer items-center gap-2 px-4 py-3 text-sm text-ink-muted lg:px-5">
                <input type="checkbox" checked={showShort} onChange={(e) => setShowShort(e.target.checked)} className="accent-ok" />
                Mostrar movimientos de menos de {SHORT_TRIP_KM} km{hiddenCount > 0 ? ` (${hiddenCount})` : ''}
              </label>
            ) : null}
          </div>
        </aside>

        <section className="flex min-h-[70vh] flex-col lg:min-h-0" aria-label="Mapa del recorrido">
          <div className="relative min-h-0 flex-1">
            <RouteMap points={points} trips={trips} selectedTripId={selectedTripId} frame={playback.frame} follow={follow} />

            <ul className="pointer-events-none absolute bottom-3 left-3 z-[1000] flex flex-col gap-1 rounded-md bg-surface/90 px-3 py-2 text-xs">
              {SPEED_BANDS.map((band) => (
                <li key={band.color} className="flex items-center gap-2">
                  <span className="h-1 w-5 rounded" style={{ background: band.color }} aria-hidden="true" />
                  {band.label}, {band.range}
                </li>
              ))}
            </ul>
          </div>

          {points.length > 0 && (
            <DayTimeline
              trips={trips}
              playback={playback}
              selectedTripId={selectedTripId}
              follow={follow}
              onFollowChange={setFollow}
            />
          )}
        </section>
      </div>
    </div>
  )
}