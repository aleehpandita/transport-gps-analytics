import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { ALERT_TYPES, DAYS, REPEAT_OPTIONS, alertTypeInfo, defaultRule, ruleSummary } from '../lib/alerts'
import { createAlertRule, deleteAlertRule, fetchAlertRules, updateAlertRule } from '../lib/apiClient'
import type { AlertRule, AlertRuleInput, AlertType, Geofence, User, Vehicle } from '../types/api'
import { Panel } from './Panel'

// Las alertas que configura la empresa: qué vigilar, en qué unidades, cuándo y a quién avisar.

const input = 'rounded-md border border-line bg-surface-raised px-3 py-1.5'
const button = 'rounded-md border border-line px-2.5 py-1 text-ink-muted hover:bg-surface-raised disabled:opacity-60'
const primary = 'rounded-md border border-ok/50 bg-ok/15 px-3 py-1.5 font-medium text-ok hover:bg-ok/25 disabled:opacity-60'

type Editing = { id: number | null; rule: AlertRuleInput } | null

interface Props {
  user: User
  vehicles: Vehicle[]
  geofences: Geofence[]
}

export function AlertRulesManager({ user, vehicles, geofences }: Props) {
  const [rules, setRules] = useState<AlertRule[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [editing, setEditing] = useState<Editing>(null)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    fetchAlertRules(controller.signal)
      .then(setRules)
      .catch((e: unknown) => {
        if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'No se pudo cargar.')
      })
    return () => controller.abort()
  }, [reload])

  async function run(task: () => Promise<unknown>): Promise<boolean> {
    setBusy(true)
    setError(null)
    try {
      await task()
      setReload((n) => n + 1)
      return true
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar.')
      return false
    } finally {
      setBusy(false)
    }
  }

  async function save(rule: AlertRuleInput) {
    const id = editing?.id ?? null
    const ok = await run(() => (id === null ? createAlertRule(rule) : updateAlertRule(id, rule)))
    if (ok) setEditing(null)
  }

  function edit(rule: AlertRule) {
    const { id, ...rest } = rule
    setEditing({ id, rule: rest })
    setError(null)
  }

  return (
    <div className="flex flex-col gap-4 text-sm">
      {error && (
        <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-late">
          {error}
        </p>
      )}

      {editing ? (
        <Panel index={2} title={editing.id === null ? 'Nueva alerta' : 'Editar alerta'}>
          <RuleForm
            key={editing.id ?? 'new'}
            initial={editing.rule}
            vehicles={vehicles}
            geofences={geofences}
            busy={busy}
            onSave={save}
            onCancel={() => setEditing(null)}
          />
        </Panel>
      ) : (
        <Panel index={1} title="Alertas configuradas" aside={rules ? `${rules.length}` : undefined}>
          <div className="flex flex-col gap-4 p-4">
            {user.can_edit && (
              <div>
                <button type="button" onClick={() => setEditing({ id: null, rule: defaultRule('overspeed') })} className={primary}>
                  Nueva alerta
                </button>
              </div>
            )}

            {!rules && !error && <p className="text-ink-muted">Cargando…</p>}
            {rules && rules.length === 0 && (
              <p className="rounded-md border border-line px-4 py-6 text-center text-ink-muted">
                Todavía no hay alertas. Crea la primera: por ejemplo, exceso de velocidad para todas las unidades.
              </p>
            )}

            {rules && rules.length > 0 && (
              <ul className="divide-y divide-line rounded-md border border-line">
                {rules.map((rule) => (
                  <li key={rule.id} className="flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3">
                    <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${alertTypeInfo(rule.type).tone}`} aria-hidden="true" />
                    <div className={`min-w-[14rem] flex-1 ${rule.active ? '' : 'opacity-60'}`}>
                      <p className="font-medium">
                        {rule.name}
                        {!rule.active && <span className="ml-2 font-normal text-ink-muted">(pausada)</span>}
                      </p>
                      <p className="mt-0.5 text-ink-muted">{ruleSummary(rule, vehicles, geofences)}</p>
                    </div>
                    {user.can_edit && (
                      <div className="flex shrink-0 flex-wrap gap-2">
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() => run(() => updateAlertRule(rule.id, { active: !rule.active }))}
                          className={button}
                        >
                          {rule.active ? 'Pausar' : 'Activar'}
                        </button>
                        <button type="button" disabled={busy} onClick={() => edit(rule)} className={button}>
                          Editar
                        </button>
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() => {
                            if (window.confirm(`¿Eliminar la alerta «${rule.name}»? Lo que ya avisó se queda en la bandeja.`)) {
                              run(() => deleteAlertRule(rule.id))
                            }
                          }}
                          className={`${button} hover:text-late`}
                        >
                          Eliminar
                        </button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Panel>
      )}
    </div>
  )
}

// ------------------------------------------------------------------ formulario

interface RuleFormProps {
  initial: AlertRuleInput
  vehicles: Vehicle[]
  geofences: Geofence[]
  busy: boolean
  onSave: (rule: AlertRuleInput) => void
  onCancel: () => void
}

function RuleForm({ initial, vehicles, geofences, busy, onSave, onCancel }: RuleFormProps) {
  const [rule, setRule] = useState<AlertRuleInput>(initial)
  const [emails, setEmails] = useState(initial.emails.join(', '))
  const [allDay, setAllDay] = useState(!initial.time_from)

  const set = (patch: Partial<AlertRuleInput>) => setRule((r) => ({ ...r, ...patch }))
  const setSetting = (patch: AlertRuleInput['settings']) => setRule((r) => ({ ...r, settings: { ...r.settings, ...patch } }))
  const info = alertTypeInfo(rule.type)
  const needsFence = rule.type === 'geofence_exit' || rule.type === 'geofence_enter'
  const afterHours = rule.type === 'after_hours'

  // Al cambiar de tipo se cargan sus valores de inicio; lo común (unidades, correos) se queda
  function changeType(type: AlertType) {
    const next = defaultRule(type)
    setRule((r) => ({
      ...next,
      vehicle_ids: r.vehicle_ids,
      emails: r.emails,
      days: r.days,
      repeat_minutes: r.repeat_minutes,
      name: r.name === alertTypeInfo(r.type).label ? next.name : r.name,
      settings: type === 'geofence_exit' || type === 'geofence_enter' ? { geofence_id: geofences[0]?.id } : next.settings,
    }))
    setAllDay(!next.time_from)
  }

  function toggleDay(day: number) {
    set({ days: rule.days.includes(day) ? rule.days.filter((d) => d !== day) : [...rule.days, day].sort() })
  }

  function toggleVehicle(id: number) {
    const current = rule.vehicle_ids ?? []
    const next = current.includes(id) ? current.filter((v) => v !== id) : [...current, id]
    set({ vehicle_ids: next })
  }

  function submit(e: FormEvent) {
    e.preventDefault()
    onSave({
      ...rule,
      time_from: allDay ? null : rule.time_from,
      time_to: allDay ? null : rule.time_to,
      emails: emails
        .split(/[\s,;]+/)
        .map((e) => e.trim())
        .filter(Boolean),
    })
  }

  return (
    <form onSubmit={submit} className="flex max-w-3xl flex-col gap-5 p-4 text-sm">
      <div className="flex flex-wrap gap-4">
        <Field label="Tipo de alerta">
          <select value={rule.type} onChange={(e) => changeType(e.target.value as AlertType)} className={input}>
            {ALERT_TYPES.map((t) => (
              <option key={t.type} value={t.type}>
                {t.label}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Nombre" grow>
          <input required maxLength={80} value={rule.name} onChange={(e) => set({ name: e.target.value })} className={`${input} w-full`} />
        </Field>
      </div>
      <p className="-mt-2 text-ink-muted">{info.help}</p>

      {/* Lo propio de cada tipo */}
      {rule.type === 'overspeed' && (
        <div className="flex flex-wrap gap-4">
          <Field label="Límite (km/h)">
            <input type="number" required min={20} max={250} value={rule.settings.speed_kmh ?? ''} onChange={(e) => setSetting({ speed_kmh: Number(e.target.value) })} className={`${input} w-28`} />
          </Field>
          <Field label="Segundos seguidos arriba del límite">
            <input type="number" required min={0} max={600} value={rule.settings.seconds ?? ''} onChange={(e) => setSetting({ seconds: Number(e.target.value) })} className={`${input} w-28`} />
          </Field>
        </div>
      )}

      {needsFence &&
        (geofences.length === 0 ? (
          <p className="rounded-md border border-risk/40 bg-risk/10 px-3 py-2 text-risk">
            Primero dibuja una geocerca en la pestaña{' '}
            <a href="#/alertas/geocercas" className="underline">
              Geocercas
            </a>
            .
          </p>
        ) : (
          <Field label="Geocerca">
            <select
              required
              value={rule.settings.geofence_id ?? ''}
              onChange={(e) => setSetting({ geofence_id: Number(e.target.value) })}
              className={input}
            >
              <option value="" disabled>
                Elige una
              </option>
              {geofences.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </select>
          </Field>
        ))}

      {rule.type === 'long_stop' && (
        <div className="flex flex-wrap items-end gap-4">
          <Field label="Minutos detenida">
            <input type="number" required min={5} max={1440} value={rule.settings.minutes ?? ''} onChange={(e) => setSetting({ minutes: Number(e.target.value) })} className={`${input} w-28`} />
          </Field>
          <label className="flex items-center gap-2 pb-1.5">
            <input type="checkbox" checked={rule.settings.except_in_geofences ?? false} onChange={(e) => setSetting({ except_in_geofences: e.target.checked })} />
            No avisar si está dentro de una de mis geocercas (la base, un hotel)
          </label>
        </div>
      )}

      {rule.type === 'signal_lost' && (
        <Field label="Minutos sin reportar">
          <input type="number" required min={5} max={1440} value={rule.settings.minutes ?? ''} onChange={(e) => setSetting({ minutes: Number(e.target.value) })} className={`${input} w-28`} />
        </Field>
      )}

      {/* Unidades */}
      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-ink-muted">Unidades</legend>
        <label className="flex items-center gap-2">
          <input type="checkbox" checked={rule.vehicle_ids === null} onChange={(e) => set({ vehicle_ids: e.target.checked ? null : [] })} />
          Todas, incluidas las que se agreguen después
        </label>
        {rule.vehicle_ids !== null && rule.vehicle_ids.length === 0 && <p className="pl-6 text-risk">Elige al menos una unidad.</p>}
        {rule.vehicle_ids !== null && (
          <div className="flex flex-wrap gap-2 pl-6">
            {vehicles.map((v) => (
              <label key={v.id} className="flex items-center gap-2 rounded-md border border-line px-2.5 py-1">
                <input type="checkbox" checked={rule.vehicle_ids?.includes(v.id) ?? false} onChange={() => toggleVehicle(v.id)} />
                {v.name}
              </label>
            ))}
          </div>
        )}
      </fieldset>

      {/* Cuándo */}
      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-ink-muted">{afterHours ? 'Horario en que la unidad NO debe moverse' : 'Cuándo vigilar'}</legend>
        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Días">
          {DAYS.map((d) => {
            const on = rule.days.includes(d.day)
            return (
              <button
                key={d.day}
                type="button"
                title={d.long}
                aria-label={d.long}
                aria-pressed={on}
                onClick={() => toggleDay(d.day)}
                className={`h-8 w-8 rounded-md border font-medium ${on ? 'border-ok/50 bg-ok/15 text-ok' : 'border-line text-ink-muted'}`}
              >
                {d.short}
              </button>
            )
          })}
        </div>
        {!afterHours && (
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={allDay} onChange={(e) => setAllDay(e.target.checked)} />
            Todo el día
          </label>
        )}
        {(!allDay || afterHours) && (
          <div className="flex flex-wrap items-end gap-3">
            <Field label="Desde">
              <input type="time" required value={rule.time_from ?? ''} onChange={(e) => set({ time_from: e.target.value })} className={input} />
            </Field>
            <Field label="Hasta">
              <input type="time" required value={rule.time_to ?? ''} onChange={(e) => set({ time_to: e.target.value })} className={input} />
            </Field>
            <span className="pb-1.5 text-ink-muted">Hora de Cancún. Si «hasta» es más temprano, cruza la medianoche.</span>
          </div>
        )}
      </fieldset>

      {/* A quién y cada cuánto */}
      <div className="flex flex-wrap gap-4">
        <Field label="No repetir antes de">
          <select value={rule.repeat_minutes} onChange={(e) => set({ repeat_minutes: Number(e.target.value) })} className={input}>
            {REPEAT_OPTIONS.map((o) => (
              <option key={o.minutes} value={o.minutes}>
                {o.label}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Correos que reciben el aviso (separados por coma)" grow>
          <input
            value={emails}
            onChange={(e) => setEmails(e.target.value)}
            placeholder="Vacío: solo en la bandeja"
            className={`${input} w-full`}
          />
        </Field>
      </div>

      <label className="flex items-center gap-2">
        <input type="checkbox" checked={rule.active} onChange={(e) => set({ active: e.target.checked })} />
        Activa
      </label>

      <div className="flex gap-3 border-t border-line pt-4">
        <button type="submit" disabled={busy || (needsFence && geofences.length === 0) || rule.days.length === 0 || rule.vehicle_ids?.length === 0} className={primary}>
          Guardar
        </button>
        <button type="button" onClick={onCancel} className={button}>
          Cancelar
        </button>
      </div>
    </form>
  )
}

function Field({ label, grow, children }: { label: string; grow?: boolean; children: ReactNode }) {
  return (
    <label className={`flex flex-col gap-1 ${grow ? 'min-w-[220px] flex-1' : ''}`}>
      <span className="text-ink-muted">{label}</span>
      {children}
    </label>
  )
}
