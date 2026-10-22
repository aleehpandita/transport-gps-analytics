import type { RouteTrip } from '../types/api'
import { PLAYBACK_SPEEDS, type Playback } from '../hooks/usePlayback'
import { formatClock, formatClockSeconds, toTimestamp } from '../lib/time'
import { tripLabel } from '../lib/tripLabel'

interface DayTimelineProps {
  trips: RouteTrip[]
  playback: Playback
  selectedTripId: number | null
  follow: boolean
  onFollowChange: (follow: boolean) => void
}

/**
 * La cinta del día: cada viaje es un bloque entre la primera y la última posición, los huecos
 * son las paradas, y el cursor marca el momento que se está reproduciendo. Toda la cinta es
 * un control deslizante: se puede arrastrar, o moverse con las flechas del teclado.
 */
export function DayTimeline({ trips, playback, selectedTripId, follow, onFollowChange }: DayTimelineProps) {
  const { first, last, time } = playback
  const span = Math.max(1, last - first)
  const pct = (ts: number) => ((Math.min(last, Math.max(first, ts)) - first) / span) * 100

  // Marcas de hora completas dentro del rango (cada hora, o cada dos si el día es largo)
  const hourStep = span > 10 * 3600 ? 2 : 1
  const ticks: number[] = []
  for (let t = Math.ceil(first / 3600) * 3600; t <= last; t += 3600 * hourStep) ticks.push(t)

  return (
    <div className="flex flex-col gap-3 border-t border-line bg-surface px-4 py-3 lg:px-6">
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={playback.toggle}
          aria-label={playback.playing ? 'Pausar' : 'Reproducir'}
          className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ok text-black focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ok"
        >
          {playback.playing ? (
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
              <rect x="2" y="1" width="3.5" height="12" rx="1" fill="currentColor" />
              <rect x="8.5" y="1" width="3.5" height="12" rx="1" fill="currentColor" />
            </svg>
          ) : (
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
              <path d="M3 1.5v11a.8.8 0 0 0 1.2.7l9-5.5a.8.8 0 0 0 0-1.4l-9-5.5A.8.8 0 0 0 3 1.5Z" fill="currentColor" />
            </svg>
          )}
        </button>

        <div className="relative h-11 flex-1">
          {/* Fondo: el día con datos */}
          <div className="absolute inset-y-1 inset-x-0 rounded-md bg-surface-raised" />

          {/* Un bloque por viaje */}
          {trips.map((trip) => {
            const left = pct(toTimestamp(trip.started_at))
            const width = Math.max(0.4, pct(toTimestamp(trip.ended_at)) - left)
            const selected = trip.id === selectedTripId

            return (
              <div
                key={trip.id}
                className={`pointer-events-none absolute inset-y-1 overflow-hidden rounded-[3px] px-1.5 text-[11px] leading-9 whitespace-nowrap ${
                  selected ? 'bg-ok text-black' : 'bg-ok/35 text-white'
                }`}
                style={{ left: `${left}%`, width: `${width}%` }}
              >
                {width > 9 ? tripLabel(trip) : ''}
              </div>
            )
          })}

          {/* Cursor de reproducción */}
          <div
            className="pointer-events-none absolute inset-y-0 w-0.5 -translate-x-1/2 rounded bg-white shadow-[0_0_0_1px_rgba(0,0,0,.6)]"
            style={{ left: `${pct(time)}%` }}
          />

          {/* Control deslizante invisible encima de toda la cinta */}
          <input
            type="range"
            min={first}
            max={last}
            step={1}
            value={Math.round(time)}
            onChange={(e) => {
              playback.setPlaying(false)
              playback.seek(Number(e.target.value))
            }}
            aria-label="Momento del día"
            aria-valuetext={formatClockSeconds(time)}
            className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
          />
        </div>

        <div className="w-28 shrink-0 text-right tabular-nums">
          <div className="text-[1rem] font-semibold text-white">{formatClockSeconds(time)}</div>
          <div className="text-xs text-ink-muted">{playback.frame ? `${Math.round(playback.frame.speed)} km/h` : ''}</div>
        </div>
      </div>

      {/* Horas debajo de la cinta, alineadas con ella */}
      <div className="relative ml-[3.25rem] mr-[7.75rem] h-3 text-[11px] text-ink-muted">
        {ticks.map((t) => (
          <span key={t} className="absolute -translate-x-1/2 tabular-nums" style={{ left: `${pct(t)}%` }}>
            {formatClock(t)}
          </span>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
        <div className="flex items-center gap-1" role="group" aria-label="Velocidad de reproducción">
          {PLAYBACK_SPEEDS.map((s) => (
            <button
              key={s}
              type="button"
              onClick={() => playback.setSpeed(s)}
              aria-pressed={playback.speed === s}
              className={`rounded-md px-2.5 py-1 tabular-nums ${
                playback.speed === s ? 'bg-surface-raised font-semibold' : 'text-ink-muted hover:bg-surface-raised'
              }`}
            >
              {s}×
            </button>
          ))}
        </div>

        <label className="flex cursor-pointer items-center gap-2 text-ink-muted">
          <input
            type="checkbox"
            checked={playback.skipStops}
            onChange={(e) => playback.setSkipStops(e.target.checked)}
            className="accent-ok"
          />
          Saltar paradas
        </label>

        <label className="flex cursor-pointer items-center gap-2 text-ink-muted">
          <input type="checkbox" checked={follow} onChange={(e) => onFollowChange(e.target.checked)} className="accent-ok" />
          Seguir unidad
        </label>

        <span className="ml-auto hidden text-xs text-ink-muted sm:inline">
          Espacio: reproducir o pausar. Flechas: un minuto atrás o adelante.
        </span>
      </div>
    </div>
  )
}