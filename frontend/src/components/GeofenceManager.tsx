import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Circle, CircleMarker, MapContainer, Polygon, TileLayer, Tooltip, useMap, useMapEvents } from 'react-leaflet'
import L, { type LatLngTuple } from 'leaflet'
import { GEOFENCE_COLORS } from '../lib/alerts'
import { createGeofence, deleteGeofence, updateGeofence } from '../lib/apiClient'
import type { Geofence, GeofenceShape, User } from '../types/api'
import { Panel } from './Panel'

// Geocercas de la empresa: se dibujan en el mapa con clics. Un círculo es un clic en el
// centro y un radio; un polígono, un clic por esquina. Luego se usan en las alertas
// "sale de" y "entra a" y para no avisar paradas largas en la base.

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
const CANCUN: LatLngTuple = [21.1619, -86.8515]

type Point = [number, number]

const input = 'rounded-md border border-line bg-surface-raised px-3 py-1.5'
const button = 'rounded-md border border-line px-2.5 py-1 text-ink-muted hover:bg-surface-raised disabled:opacity-60'
const primary = 'rounded-md border border-ok/50 bg-ok/15 px-3 py-1.5 font-medium text-ok hover:bg-ok/25 disabled:opacity-60'

interface Draft {
  id: number | null
  name: string
  color: string
  shape: GeofenceShape
  center: Point | null
  radius: number
  points: Point[]
  // Al editar: false = conserva el dibujo de antes; true = se está dibujando de nuevo
  redraw: boolean
}

const newDraft = (shape: GeofenceShape, color: string): Draft => ({
  id: null,
  name: '',
  color,
  shape,
  center: null,
  radius: 300,
  points: [],
  redraw: true,
})

interface Props {
  user: User
  geofences: Geofence[]
  onChanged: () => void
}

export function GeofenceManager({ user, geofences, onChanged }: Props) {
  const [draft, setDraft] = useState<Draft | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const nextColor = GEOFENCE_COLORS[geofences.length % GEOFENCE_COLORS.length]

  function startEdit(g: Geofence) {
    setError(null)
    setDraft({
      id: g.id,
      name: g.name,
      color: g.color,
      shape: g.shape,
      center: [g.latitude, g.longitude],
      radius: g.radius_m ?? 300,
      points: g.points ?? [],
      redraw: false,
    })
  }

  function onMapClick(point: Point) {
    setDraft((d) => {
      if (!d || !d.redraw) return d
      return d.shape === 'circle' ? { ...d, center: point } : { ...d, points: [...d.points, point] }
    })
  }

  async function run(task: () => Promise<unknown>): Promise<boolean> {
    setBusy(true)
    setError(null)
    try {
      await task()
      onChanged()
      return true
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar.')
      return false
    } finally {
      setBusy(false)
    }
  }

  async function save(e: FormEvent) {
    e.preventDefault()
    if (!draft) return

    const geometry =
      draft.shape === 'circle'
        ? { shape: 'circle' as const, latitude: draft.center![0], longitude: draft.center![1], radius_m: draft.radius }
        : { shape: 'polygon' as const, points: draft.points }
    const body = { name: draft.name.trim(), color: draft.color, ...geometry }

    const ok = await run(() => (draft.id === null ? createGeofence(body) : updateGeofence(draft.id, body)))
    if (ok) setDraft(null)
  }

  const ready = draft !== null && draft.name.trim() !== '' && (draft.shape === 'circle' ? draft.center !== null : draft.points.length >= 3)

  return (
    <div className="grid gap-4 text-sm lg:grid-cols-[1fr_360px]">
      <div className="relative h-[60vh] min-h-[360px] overflow-hidden rounded-lg border border-line">
        <MapContainer center={CANCUN} zoom={12} scrollWheelZoom className="h-full w-full">
          <TileLayer attribution={OSM_ATTRIBUTION} url={OSM_URL} className="map-tiles-dark" />
          <ClickCatcher active={draft?.redraw ?? false} onClick={onMapClick} />
          <FitGeofences geofences={geofences} />
          <FocusDraft draft={draft} />

          {geofences
            .filter((g) => g.id !== draft?.id)
            .map((g) => (
              <GeofenceShapeLayer key={g.id} geofence={g} />
            ))}

          {draft && <DraftLayer draft={draft} />}
        </MapContainer>

        {draft?.redraw && (
          <p className="pointer-events-none absolute left-14 right-3 top-3 z-[1000] rounded-md bg-surface/95 px-3 py-2 text-center shadow">
            {draft.shape === 'circle'
              ? draft.center
                ? 'Ajusta el radio a la derecha, o haz clic en otro lugar para mover el centro.'
                : 'Haz clic en el mapa donde va el centro del círculo.'
              : draft.points.length < 3
                ? `Haz clic en cada esquina de la zona (${draft.points.length} de mínimo 3).`
                : `${draft.points.length} esquinas. Sigue marcando o guarda.`}
          </p>
        )}
      </div>

      <div className="flex flex-col gap-4">
        {error && (
          <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-late">
            {error}
          </p>
        )}

        {draft ? (
          <Panel index={2} title={draft.id === null ? 'Nueva geocerca' : 'Editar geocerca'}>
            <form onSubmit={save} className="flex flex-col gap-4 p-4">
              <label className="flex flex-col gap-1">
                <span className="text-ink-muted">Nombre</span>
                <input
                  required
                  autoFocus
                  maxLength={80}
                  value={draft.name}
                  placeholder="Base, Aeropuerto, Zona Hotelera…"
                  onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                  className={input}
                />
              </label>

              <div className="flex flex-col gap-1">
                <span className="text-ink-muted">Color</span>
                <div className="flex gap-2">
                  {GEOFENCE_COLORS.map((c) => (
                    <button
                      key={c}
                      type="button"
                      aria-label={`Color ${c}`}
                      aria-pressed={draft.color === c}
                      onClick={() => setDraft({ ...draft, color: c })}
                      className={`h-7 w-7 rounded-full border-2 ${draft.color === c ? 'border-ink' : 'border-transparent'}`}
                      style={{ background: c }}
                    />
                  ))}
                </div>
              </div>

              {draft.redraw ? (
                <>
                  <div role="group" aria-label="Forma" className="flex w-fit rounded-md border border-line">
                    {(['circle', 'polygon'] as const).map((shape) => (
                      <button
                        key={shape}
                        type="button"
                        aria-pressed={draft.shape === shape}
                        onClick={() => setDraft({ ...draft, shape, center: null, points: [] })}
                        className={`px-3 py-1.5 ${draft.shape === shape ? 'bg-surface-raised font-medium' : 'text-ink-muted hover:text-ink'}`}
                      >
                        {shape === 'circle' ? 'Círculo' : 'Polígono'}
                      </button>
                    ))}
                  </div>

                  {draft.shape === 'circle' ? (
                    <label className="flex flex-col gap-1">
                      <span className="text-ink-muted">Radio: {formatMeters(draft.radius)}</span>
                      <input
                        type="range"
                        min={30}
                        max={5000}
                        step={10}
                        value={draft.radius}
                        onChange={(e) => setDraft({ ...draft, radius: Number(e.target.value) })}
                      />
                      <input
                        type="number"
                        min={30}
                        max={50000}
                        value={draft.radius}
                        onChange={(e) => setDraft({ ...draft, radius: Number(e.target.value) })}
                        className={`${input} w-32`}
                        aria-label="Radio en metros"
                      />
                    </label>
                  ) : (
                    <div className="flex gap-2">
                      <button
                        type="button"
                        disabled={draft.points.length === 0}
                        onClick={() => setDraft({ ...draft, points: draft.points.slice(0, -1) })}
                        className={button}
                      >
                        Quitar el último punto
                      </button>
                      <button
                        type="button"
                        disabled={draft.points.length === 0}
                        onClick={() => setDraft({ ...draft, points: [] })}
                        className={button}
                      >
                        Empezar de nuevo
                      </button>
                    </div>
                  )}
                </>
              ) : (
                <button type="button" onClick={() => setDraft({ ...draft, redraw: true, center: null, points: [] })} className={`${button} w-fit`}>
                  Volver a dibujarla
                </button>
              )}

              <div className="flex gap-3 border-t border-line pt-4">
                <button type="submit" disabled={busy || !ready} className={primary}>
                  Guardar
                </button>
                <button type="button" onClick={() => setDraft(null)} className={button}>
                  Cancelar
                </button>
              </div>
            </form>
          </Panel>
        ) : (
          <Panel index={1} title="Geocercas" aside={`${geofences.length}`}>
            <div className="flex flex-col gap-3 p-4">
              {user.can_edit && (
                <div className="flex flex-wrap gap-2">
                  <button type="button" onClick={() => setDraft(newDraft('circle', nextColor))} className={primary}>
                    Nuevo círculo
                  </button>
                  <button type="button" onClick={() => setDraft(newDraft('polygon', nextColor))} className={primary}>
                    Nuevo polígono
                  </button>
                </div>
              )}

              {geofences.length === 0 && (
                <p className="text-ink-muted">
                  Aún no hay geocercas. Dibuja la base, el aeropuerto o cualquier zona de la que quieras saber cuándo entran o salen
                  tus unidades.
                </p>
              )}

              <ul className="divide-y divide-line">
                {geofences.map((g) => (
                  <li key={g.id} className="flex items-center gap-3 py-2">
                    <span className="h-3 w-3 shrink-0 rounded-full" style={{ background: g.color }} aria-hidden="true" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium">{g.name}</p>
                      <p className="text-ink-muted">
                        {g.shape === 'circle' ? `Círculo de ${formatMeters(g.radius_m ?? 0)}` : `Polígono de ${g.points?.length ?? 0} esquinas`}
                      </p>
                    </div>
                    {user.can_edit && (
                      <>
                        <button type="button" disabled={busy} onClick={() => startEdit(g)} className={button}>
                          Editar
                        </button>
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() => {
                            if (window.confirm(`¿Eliminar la geocerca «${g.name}»?`)) run(() => deleteGeofence(g.id))
                          }}
                          className={`${button} hover:text-late`}
                        >
                          Eliminar
                        </button>
                      </>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          </Panel>
        )}
      </div>
    </div>
  )
}

function formatMeters(m: number): string {
  return m >= 1000 ? `${(m / 1000).toLocaleString('es-MX', { maximumFractionDigits: 1 })} km` : `${m} m`
}

function GeofenceShapeLayer({ geofence: g }: { geofence: Geofence }) {
  const style = { color: g.color, weight: 2, fillOpacity: 0.15 }
  const label = (
    <Tooltip direction="center" permanent className="geofence-label">
      {g.name}
    </Tooltip>
  )

  return g.shape === 'circle' ? (
    <Circle center={[g.latitude, g.longitude]} radius={g.radius_m ?? 0} pathOptions={style}>
      {label}
    </Circle>
  ) : (
    <Polygon positions={(g.points ?? []) as LatLngTuple[]} pathOptions={style}>
      {label}
    </Polygon>
  )
}

function DraftLayer({ draft }: { draft: Draft }) {
  const style = { color: draft.color, weight: 2, dashArray: '6 6', fillOpacity: 0.2 }

  if (draft.shape === 'circle') {
    return draft.center ? <Circle center={draft.center} radius={draft.radius} pathOptions={style} /> : null
  }

  return (
    <>
      {draft.points.length >= 2 && <Polygon positions={draft.points} pathOptions={style} />}
      {draft.points.map((p, i) => (
        <CircleMarker key={i} center={p} radius={4} pathOptions={{ color: draft.color, fillOpacity: 1 }} />
      ))}
    </>
  )
}

// Clics en el mapa solo mientras se dibuja; el cursor en cruz lo indica
function ClickCatcher({ active, onClick }: { active: boolean; onClick: (p: Point) => void }) {
  const map = useMapEvents({
    click(e) {
      if (active) onClick([Number(e.latlng.lat.toFixed(6)), Number(e.latlng.lng.toFixed(6))])
    },
  })

  useEffect(() => {
    map.getContainer().style.cursor = active ? 'crosshair' : ''
  }, [map, active])

  return null
}

// Al cargar, encuadra todas las geocercas
function FitGeofences({ geofences }: { geofences: Geofence[] }) {
  const map = useMap()
  const key = geofences.map((g) => g.id).join(',')

  const bounds = useMemo(() => {
    if (geofences.length === 0) return null
    const b = L.latLngBounds([])
    for (const g of geofences) {
      if (g.shape === 'circle') b.extend(L.latLng(g.latitude, g.longitude).toBounds((g.radius_m ?? 0) * 2))
      else for (const p of g.points ?? []) b.extend(p as LatLngTuple)
    }
    return b
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  useEffect(() => {
    if (bounds?.isValid()) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 })
  }, [map, bounds])

  return null
}

// Al editar una geocerca, el mapa va a ella
function FocusDraft({ draft }: { draft: Draft | null }) {
  const map = useMap()
  const id = draft?.id ?? null

  useEffect(() => {
    if (!draft || draft.id === null || draft.redraw) return
    if (draft.shape === 'circle' && draft.center) {
      map.fitBounds(L.latLng(draft.center).toBounds(draft.radius * 2.4))
    } else if (draft.points.length) {
      map.fitBounds(L.latLngBounds(draft.points), { padding: [30, 30] })
    }
    // Solo al abrir la edición, no en cada cambio del formulario
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [map, id])

  return null
}
