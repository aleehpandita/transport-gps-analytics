import { Fragment, useEffect, useState } from 'react'
import { createPlace, fetchOperation, fetchVehicles, reviewTrip } from '../lib/apiClient'
import { loadingFrom, shown, type LoadState } from '../lib/loadState'
import { cancunDateOf, cancunToday, formatClock, formatDayLabel, formatDuration, shiftDate, toTimestamp } from '../lib/time'
import type {
  LegKind,
  OperationDay,
  OperationLeg,
  OperationService,
  ReviewStatus,
  TripReview,
  UnnamedSpot,
  User,
  Vehicle,
} from '../types/api'
import { NavLinks } from './NavLinks'

// Operación del día armada solo con GPS: qué servicios se hicieron, a qué hotel y terminal,
// cuánto esperó la unidad y cuántos km llevó pasajeros. No necesita la orden de servicio.
// El GPS no sabe si alguien se subió: lo dudoso sale "por confirmar" y quien opera lo marca.

const KIND_INFO: Record<LegKind, { label: string; tone: string }> = {
  llegada: { label: 'Llegada', tone: 'bg-ok' },
  salida: { label: 'Salida', tone: 'bg-ok' },
  regreso: { label: 'Regreso vacío', tone: 'bg-ink-muted' },
  posicionamiento: { label: 'Vacío', tone: 'bg-ink-muted' },
  maniobra: { label: 'Maniobra', tone: 'bg-ink-muted' },
}

const REVIEW_OPTIONS: { id: ReviewStatus; label: string; hint: string }[] = [
  { id: 'service', label: 'Servicio', hint: 'Llevó pasajeros' },
  { id: 'empty', label: 'Sin pasajeros', hint: 'Fue una vuelta o un movimiento vacío' },
  { id: 'no_show', label: 'No-show', hint: 'Fue por los pasajeros y no se presentaron' },
  { id: 'personal', label: 'Uso personal', hint: 'El chofer usó la unidad para algo propio' },
]
const REVIEW_LABEL: Record<ReviewStatus, string> = {
  service: 'Servicio',
  empty: 'Sin pasajeros',
  no_show: 'No-show',
  personal: 'Uso personal',
}

const lobby = (name: string | null) => name?.replace(/\s+lobby$/i, '') ?? null
const mapsUrl = (lat: number, lng: number) => `https://www.google.com/maps?q=${lat},${lng}`

interface OperationsViewProps {
  user: User
  onLogout: () => void
}

export function OperationsView({ user, onLogout }: OperationsViewProps) {
  const today = cancunToday()
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [vehicleId, setVehicleId] = useState<number | null>(null)
  const [date, setDate] = useState(today)
  const [reload, setReload] = useState(0)
  const [state, setState] = useState<LoadState<OperationDay>>({ status: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    fetchVehicles(controller.signal)
      .then(setVehicles)
      .catch(() => {})
    return () => controller.abort()
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    setState(loadingFrom)
    fetchOperation(date, vehicleId, controller.signal)
      .then((value) => {
        if (!controller.signal.aborted) setState({ status: 'ready', value })
      })
      .catch((e: unknown) => {
        if (controller.signal.aborted) return
        setState({ status: 'error', message: e instanceof Error ? e.message : 'Error desconocido' })
      })
    return () => controller.abort()
  }, [date, vehicleId, reload])

  const day = shown(state)
  const busy = state.status === 'loading'
  const manyVehicles = vehicleId === null && vehicles.length > 1
  const isAdmin = user.role === 'admin'

  return (
    <div className="flex min-h-screen flex-col">
      <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 lg:px-6">
        <h1 className="text-lg font-semibold">Operación</h1>
        <div className="ml-auto flex flex-wrap items-center gap-3 text-sm">
          <NavLinks current="operation" />
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

      {/* Unidad y día */}
      <div className="flex flex-wrap items-end gap-x-6 gap-y-3 border-b border-line px-4 py-4 text-sm lg:px-6">
        <label className="flex flex-col gap-1">
          <span className="text-ink-muted">Unidad</span>
          <select
            value={vehicleId ?? ''}
            onChange={(e) => setVehicleId(e.target.value === '' ? null : Number(e.target.value))}
            className="rounded-md border border-line bg-surface-raised px-3 py-1.5"
          >
            {vehicles.length > 1 && <option value="">Todas las unidades</option>}
            {vehicles.map((v) => (
              <option key={v.id} value={v.id}>
                {v.name}
              </option>
            ))}
          </select>
        </label>

        <div className="flex flex-col gap-1">
          <span className="text-ink-muted">Día</span>
          <div className="flex items-center gap-1">
            <button
              type="button"
              aria-label="Día anterior"
              onClick={() => setDate(shiftDate(date, -1))}
              className="rounded-md border border-line px-2.5 py-1.5 hover:bg-surface-raised"
            >
              ‹
            </button>
            <input
              type="date"
              value={date}
              max={today}
              onChange={(e) => e.target.value && setDate(e.target.value)}
              className="rounded-md border border-line bg-surface-raised px-3 py-1.5"
            />
            <button
              type="button"
              aria-label="Día siguiente"
              disabled={date >= today}
              onClick={() => setDate(shiftDate(date, 1))}
              className="rounded-md border border-line px-2.5 py-1.5 hover:bg-surface-raised disabled:opacity-40"
            >
              ›
            </button>
          </div>
        </div>
        <span className="pb-1.5 text-ink-muted">{formatDayLabel(date)}</span>
      </div>

      <main className={`flex-1 space-y-6 px-4 py-4 lg:px-6 ${busy && day ? 'opacity-60' : ''}`}>
        {state.status === 'error' && (
          <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-sm text-late">
            No se pudo cargar la operación ({state.message}). Revisa que Laravel esté corriendo y vuelve a intentar.
          </p>
        )}
        {!day && busy && <p className="text-sm text-ink-muted">Cargando…</p>}
        {day && day.legs.length === 0 && <p className="text-sm text-ink-muted">No hubo viajes este día.</p>}

        {day && day.legs.length > 0 && (
          <>
            <Summary day={day} />
            {day.unnamed.length > 0 && (
              <UnnamedSpots spots={day.unnamed} canApprove={isAdmin} onApproved={() => setReload((n) => n + 1)} />
            )}
            <ServicesTable services={day.services} showVehicle={manyVehicles} onReviewed={() => setReload((n) => n + 1)} />
            <LegsList day={day} showVehicle={manyVehicles} onReviewed={() => setReload((n) => n + 1)} />
          </>
        )}
      </main>
    </div>
  )
}

function Summary({ day }: { day: OperationDay }) {
  const s = day.summary
  const totalKm = s.km_with_passengers + s.km_pending + s.km_empty
  const pct = (km: number) => (totalKm > 0 ? Math.round((km / totalKm) * 100) : 0)
  const workday = s.first_start && s.last_end ? toTimestamp(s.last_end) - toTimestamp(s.first_start) : null

  const cards = [
    { label: 'Llegadas', value: String(s.arrivals), hint: 'aeropuerto → hotel' },
    { label: 'Salidas', value: String(s.departures), hint: 'hotel → aeropuerto' },
    {
      label: 'Por confirmar',
      value: String(s.pending),
      hint: s.pending > 0 ? `${s.km_pending.toFixed(1)} km sin contar como servicio` : 'nada pendiente',
      warn: s.pending > 0,
    },
    {
      label: 'Km con pasajeros',
      value: `${s.km_with_passengers.toFixed(1)} km`,
      hint: `${pct(s.km_with_passengers)} % del recorrido`,
    },
    { label: 'Km vacíos', value: `${s.km_empty.toFixed(1)} km`, hint: 'regresos y posicionamientos' },
    {
      label: 'Jornada',
      value: workday !== null ? formatDuration(workday) : '-',
      hint: s.first_start && s.last_end ? `${formatClock(s.first_start)} a ${formatClock(s.last_end)}` : '',
    },
  ]

  return (
    <section aria-label="Resumen del día">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {cards.map((c) => (
          <div
            key={c.label}
            className={`rounded-lg border px-4 py-3 ${c.warn ? 'border-risk/50 bg-risk/10' : 'border-line bg-surface-raised'}`}
          >
            <div className="text-xs text-ink-muted">{c.label}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums">{c.value}</div>
            <div className="text-xs text-ink-muted">{c.hint}</div>
          </div>
        ))}
      </div>
      {totalKm > 0 && (
        <div className="mt-3 flex h-2 overflow-hidden rounded-full bg-line" aria-hidden="true">
          <div className="bg-ok" style={{ width: `${pct(s.km_with_passengers)}%` }} />
          <div className="bg-risk" style={{ width: `${pct(s.km_pending)}%` }} />
        </div>
      )}
    </section>
  )
}

// Estado de un servicio: lo que supone el GPS o lo que confirmó una persona
function StatusBadge({ status, review }: { status: OperationService['status'] | null; review: TripReview | null }) {
  if (review) {
    const tone =
      review.status === 'service'
        ? 'bg-ok/15 text-ok'
        : review.status === 'no_show'
          ? 'bg-late/15 text-late'
          : 'bg-line text-ink-muted'
    return (
      <span className={`rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${tone}`}>
        {REVIEW_LABEL[review.status]} ✓
      </span>
    )
  }
  if (status === 'por_confirmar') {
    return (
      <span className="rounded-full bg-risk/15 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-risk">Por confirmar</span>
    )
  }
  if (status === 'probable') {
    return <span className="rounded-full bg-ok/10 px-2 py-0.5 text-xs whitespace-nowrap text-ok">Probable</span>
  }
  return null
}

// Marcar qué fue un viaje, con una nota opcional
function ReviewEditor({
  tripId,
  review,
  onDone,
}: {
  tripId: number
  review: TripReview | null
  onDone: (changed: boolean) => void
}) {
  const [choice, setChoice] = useState<ReviewStatus | null>(review?.status ?? null)
  const [note, setNote] = useState(review?.note ?? '')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function save(status: ReviewStatus | 'clear') {
    setSaving(true)
    setError(null)
    try {
      await reviewTrip(tripId, status, note.trim() === '' ? null : note.trim())
      onDone(true)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar')
      setSaving(false)
    }
  }

  return (
    <div className="space-y-3 rounded-md border border-line bg-surface-raised p-3 text-sm">
      <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Qué fue este viaje">
        {REVIEW_OPTIONS.map((o) => (
          <button
            key={o.id}
            type="button"
            role="radio"
            aria-checked={choice === o.id}
            title={o.hint}
            onClick={() => setChoice(o.id)}
            className={`rounded-full border px-3 py-1 ${choice === o.id ? 'border-ok bg-ok/15 font-medium text-white' : 'border-line text-ink-muted hover:bg-surface'}`}
          >
            {o.label}
          </button>
        ))}
      </div>
      <textarea
        value={note}
        onChange={(e) => setNote(e.target.value)}
        rows={2}
        placeholder="Nota (opcional): qué pasó, quién lo reportó..."
        aria-label="Nota"
        className="w-full rounded-md border border-line bg-surface px-3 py-2"
      />
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          disabled={!choice || saving}
          onClick={() => choice && save(choice)}
          className="rounded-md border border-ok/50 bg-ok/15 px-3 py-1.5 font-medium text-ok hover:bg-ok/25 disabled:opacity-40"
        >
          {saving ? 'Guardando…' : 'Guardar'}
        </button>
        {review && (
          <button
            type="button"
            disabled={saving}
            onClick={() => save('clear')}
            className="rounded-md border border-line px-3 py-1.5 text-ink-muted hover:bg-surface"
          >
            Quitar confirmación
          </button>
        )}
        <button type="button" onClick={() => onDone(false)} className="px-2 py-1.5 text-ink-muted hover:text-white">
          Cancelar
        </button>
        {error && <span className="text-late">{error}</span>}
      </div>
    </div>
  )
}

function ServicesTable({
  services,
  showVehicle,
  onReviewed,
}: {
  services: OperationService[]
  showVehicle: boolean
  onReviewed: () => void
}) {
  const [editing, setEditing] = useState<number | null>(null)

  if (services.length === 0) {
    return <p className="text-sm text-ink-muted">No se detectaron servicios con pasajeros este día.</p>
  }

  const columns = showVehicle ? 9 : 8

  return (
    <section aria-label="Servicios">
      <h2 className="mb-2 text-sm font-semibold">Servicios detectados</h2>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="border-b border-line">
            <tr className="text-left text-ink-muted">
              <th className="px-3 py-2 font-normal">Hora</th>
              <th className="px-3 py-2 font-normal">Tipo</th>
              <th className="px-3 py-2 font-normal">Estado</th>
              {showVehicle && <th className="px-3 py-2 font-normal">Unidad</th>}
              <th className="px-3 py-2 font-normal">Hotel</th>
              <th className="px-3 py-2 font-normal">Terminal</th>
              <th className="px-3 py-2 font-normal">Detalle</th>
              <th className="px-3 py-2 text-right font-normal">Km</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {services.map((s) => (
              <Fragment key={s.trip_id}>
                <tr
                  className={`align-top hover:bg-surface-raised/60 ${s.status === 'por_confirmar' && !s.review ? 'bg-risk/5' : ''}`}
                >
                  <td className="px-3 py-2 tabular-nums whitespace-nowrap">{formatClock(s.left_at)}</td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    {s.type === 'llegada' ? 'Llegada' : s.type === 'salida' ? 'Salida' : 'Otro'}
                  </td>
                  <td className="px-3 py-2">
                    <StatusBadge status={s.status} review={s.review} />
                  </td>
                  {showVehicle && <td className="px-3 py-2 whitespace-nowrap">{s.vehicle}</td>}
                  <td className="px-3 py-2">
                    {lobby(s.hotel) ?? (
                      <a
                        href={mapsUrl(s.hotel_latitude, s.hotel_longitude)}
                        target="_blank"
                        rel="noreferrer"
                        className="text-risk underline underline-offset-4"
                      >
                        Sin nombre (ver mapa)
                      </a>
                    )}
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap">{s.terminal?.replace(/^Aeropuerto\s+/, '') ?? '-'}</td>
                  <td className="px-3 py-2 text-ink-muted">
                    {detail(s)}
                    {!s.review && s.reasons.length > 0 && <div className="mt-1 text-risk">{s.reasons.join('. ')}.</div>}
                    {s.review && (
                      <div className="mt-1 text-xs">
                        {s.review.note && <span className="text-white">“{s.review.note}” </span>}
                        Marcado por {s.review.by ?? 'alguien'}, {formatDayLabel(cancunDateOf(s.review.at))}{' '}
                        {formatClock(s.review.at)}
                      </div>
                    )}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">{s.km.toFixed(1)}</td>
                  <td className="px-3 py-2 text-right">
                    <button
                      type="button"
                      onClick={() => setEditing(editing === s.trip_id ? null : s.trip_id)}
                      className="rounded-md border border-line px-2.5 py-1 whitespace-nowrap text-ink-muted hover:bg-surface-raised"
                    >
                      {s.review ? 'Cambiar' : 'Confirmar'}
                    </button>
                  </td>
                </tr>
                {editing === s.trip_id && (
                  <tr>
                    <td colSpan={columns} className="px-3 pb-3">
                      <ReviewEditor
                        tripId={s.trip_id}
                        review={s.review}
                        onDone={(changed) => {
                          setEditing(null)
                          if (changed) onReviewed()
                        }}
                      />
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

// Los horarios de cada servicio en una línea
function detail(s: OperationService): string {
  const drive = formatDuration(toTimestamp(s.arrived_at) - toTimestamp(s.left_at))
  if (s.type === 'otro') {
    return `Viaje de ${formatClock(s.left_at)} a ${formatClock(s.arrived_at)} (${drive}), marcado como servicio`
  }
  if (s.type === 'llegada') {
    return `Salió del aeropuerto ${formatClock(s.left_at)}, en el hotel ${formatClock(s.arrived_at)} (${drive})`
  }
  const waited =
    s.reached_hotel_at && s.wait_seconds !== null
      ? `Llegó al hotel ${formatClock(s.reached_hotel_at)}, esperó ${formatDuration(s.wait_seconds)}; `
      : ''
  return `${waited}salió ${formatClock(s.left_at)}, en el aeropuerto ${formatClock(s.arrived_at)} (${drive})`
}

function UnnamedSpots({ spots, canApprove, onApproved }: { spots: UnnamedSpot[]; canApprove: boolean; onApproved: () => void }) {
  return (
    <section aria-label="Puntos sin nombre" className="rounded-lg border border-risk/40 bg-risk/5 px-4 py-3">
      <h2 className="text-sm font-semibold">Puntos de servicio sin nombre</h2>
      <p className="mt-1 text-sm text-ink-muted">
        Aquí se subió o bajó gente, pero el lugar no está en el catálogo.
        {canApprove ? ' Dales nombre para que aparezcan en reportes y alertas.' : ' Un administrador puede darles nombre.'}
      </p>
      <ul className="mt-3 divide-y divide-line">
        {spots.map((spot) => (
          <SpotRow key={`${spot.latitude},${spot.longitude}`} spot={spot} canApprove={canApprove} onApproved={onApproved} />
        ))}
      </ul>
    </section>
  )
}

function SpotRow({ spot, canApprove, onApproved }: { spot: UnnamedSpot; canApprove: boolean; onApproved: () => void }) {
  const [name, setName] = useState(spot.suggestion ? `${spot.suggestion.name} lobby` : '')
  const [status, setStatus] = useState<{ kind: 'idle' | 'saving' | 'error'; message?: string }>({ kind: 'idle' })

  async function approve() {
    setStatus({ kind: 'saving' })
    try {
      await createPlace({ name: name.trim(), latitude: spot.latitude, longitude: spot.longitude, type: 'hotel', radius_m: 60 })
      onApproved()
    } catch (e) {
      setStatus({ kind: 'error', message: e instanceof Error ? e.message : 'No se pudo guardar' })
    }
  }

  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 py-2 text-sm">
      <div className="min-w-48">
        <div>
          {formatDayLabel(cancunDateOf(spot.first_seen_at))}, {formatClock(spot.first_seen_at)}
          {spot.services > 1 && <span className="text-ink-muted"> ({spot.services} servicios)</span>}
        </div>
        <div className="text-ink-muted">
          {spot.suggestion
            ? `Parece ser ${spot.suggestion.name} (a ${spot.suggestion.distance_m} m)`
            : 'Sin hotel cercano en el mapa'}
        </div>
      </div>
      <a
        href={mapsUrl(spot.latitude, spot.longitude)}
        target="_blank"
        rel="noreferrer"
        className="underline decoration-line underline-offset-4 hover:decoration-ok"
      >
        Ver en mapa
      </a>
      {canApprove && (
        <div className="flex flex-1 flex-wrap items-center gap-2">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Nombre, por ejemplo Cancun Bay lobby"
            aria-label="Nombre del lugar"
            className="min-w-56 flex-1 rounded-md border border-line bg-surface-raised px-3 py-1.5"
          />
          <button
            type="button"
            disabled={name.trim() === '' || status.kind === 'saving'}
            onClick={approve}
            className="rounded-md border border-ok/50 bg-ok/15 px-3 py-1.5 font-medium text-ok hover:bg-ok/25 disabled:opacity-40"
          >
            {status.kind === 'saving' ? 'Guardando…' : 'Dar de alta'}
          </button>
          {status.kind === 'error' && <span className="text-late">{status.message}</span>}
        </div>
      )}
    </li>
  )
}

function LegsList({ day, showVehicle, onReviewed }: { day: OperationDay; showVehicle: boolean; onReviewed: () => void }) {
  const [editing, setEditing] = useState<number | null>(null)

  return (
    <details className="rounded-lg border border-line">
      <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">
        Todos los movimientos ({day.legs.length})
        <span className="ml-2 font-normal text-ink-muted">Aquí también puedes marcar un viaje como servicio o uso personal</span>
      </summary>
      <ul className="divide-y divide-line border-t border-line">
        {day.legs.map((l) => (
          <LegRow
            key={l.trip_id}
            leg={l}
            date={day.date}
            showVehicle={showVehicle}
            editing={editing === l.trip_id}
            onToggle={() => setEditing(editing === l.trip_id ? null : l.trip_id)}
            onDone={(changed) => {
              setEditing(null)
              if (changed) onReviewed()
            }}
          />
        ))}
      </ul>
    </details>
  )
}

function LegRow({
  leg: l,
  date,
  showVehicle,
  editing,
  onToggle,
  onDone,
}: {
  leg: OperationLeg
  date: string
  showVehicle: boolean
  editing: boolean
  onToggle: () => void
  onDone: (changed: boolean) => void
}) {
  return (
    <li className="px-4 py-2 text-sm">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
        <span
          className={`size-2.5 shrink-0 rounded-full ${l.with_passengers ? 'bg-ok' : KIND_INFO[l.kind].tone}`}
          aria-hidden="true"
        />
        <span className="w-28 tabular-nums">
          {formatClock(l.started_at)} a {formatClock(l.ended_at)}
        </span>
        <span className={`w-32 ${l.with_passengers ? 'font-medium' : 'text-ink-muted'}`}>{KIND_INFO[l.kind].label}</span>
        <StatusBadge status={l.status} review={l.review} />
        {showVehicle && <span className="text-ink-muted">{l.vehicle}</span>}
        <span className="min-w-0 flex-1">
          {lobby(l.origin) ?? 'Sin nombre'} → {lobby(l.destination) ?? 'Sin nombre'}
        </span>
        <span className="tabular-nums text-ink-muted">{l.km.toFixed(1)} km</span>
        <a
          href={`#/historial/${l.vehicle_id}/${date}`}
          className="underline decoration-line underline-offset-4 hover:decoration-ok"
        >
          Ver
        </a>
        <button
          type="button"
          onClick={onToggle}
          className="rounded-md border border-line px-2.5 py-1 text-ink-muted hover:bg-surface-raised"
        >
          {l.review ? 'Cambiar' : 'Marcar'}
        </button>
      </div>
      {l.review?.note && <div className="mt-1 pl-7 text-xs text-ink-muted">“{l.review.note}”</div>}
      {editing && (
        <div className="mt-2">
          <ReviewEditor tripId={l.trip_id} review={l.review} onDone={onDone} />
        </div>
      )}
    </li>
  )
}