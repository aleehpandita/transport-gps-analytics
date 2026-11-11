import { useEffect, useState, type FormEvent } from 'react'
import { PageHeader } from './PageHeader'
import { Panel } from './Panel'
import { UserManager, type UserActions } from './UserManager'
import {
  addCompanyVehicle,
  createAdminUser,
  createCompany,
  deleteAdminUser,
  fetchAdminCompanies,
  fetchAdminCompany,
  fetchUnassignedVehicles,
  resetAdminUserPassword,
  updateAdminUser,
  updateAdminVehicle,
  updateCompany,
  viewAsCompany,
  type CompanyInput,
} from '../lib/apiClient'
import { formatLastSeen } from '../lib/format'
import type { AdminCompany, AdminCompanyDetail, AdminVehicle, SignalStatus, User } from '../types/api'

// Panel de EkMira: nuestros clientes. Lista de empresas con lo que importa de un vistazo
// (cuántas unidades, cuáles no reportan) y, al elegir una, sus unidades y usuarios.

const input = 'rounded-md border border-line bg-surface-raised px-3 py-1.5'
const button = 'rounded-md border border-line px-2.5 py-1 text-ink-muted hover:bg-surface-raised disabled:opacity-60'
const primary = 'rounded-md border border-ok/50 bg-ok/15 px-3 py-1.5 font-medium text-ok hover:bg-ok/25 disabled:opacity-60'

const userActions: UserActions = {
  create: () => Promise.reject(new Error('Elige una empresa')),
  update: updateAdminUser,
  resetPassword: resetAdminUserPassword,
  remove: deleteAdminUser,
}

export function ClientsView({ user, onLogout }: { user: User; onLogout: () => void }) {
  const [companies, setCompanies] = useState<AdminCompany[] | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    fetchAdminCompanies(controller.signal)
      .then((list) => {
        setCompanies(list)
        setSelectedId((current) => current ?? list[0]?.id ?? null)
      })
      .catch((e) => {
        if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'No se pudo cargar.')
      })
    return () => controller.abort()
  }, [reload])

  const refresh = () => setReload((n) => n + 1)
  const totals = companies && {
    vehicles: companies.reduce((sum, c) => sum + (c.vehicles_count ?? 0), 0),
    noSignal: companies.reduce((sum, c) => sum + (c.vehicles_without_signal ?? 0), 0),
  }

  return (
    <div className="flex min-h-screen flex-col">
      <PageHeader title="Clientes" current="clients" user={user} onLogout={onLogout} />

      <main className="grid flex-1 gap-4 p-4 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)] lg:p-6">
        <Panel
          index={1}
          title="Empresas"
          aside={totals ? `${companies?.length} · ${totals.vehicles} unidades · ${totals.noSignal} sin señal` : undefined}
        >
          <div className="flex flex-col gap-3 p-4 text-sm">
            {error && <p className="text-late">{error}</p>}
            <ul className="divide-y divide-line rounded-md border border-line">
              {companies?.map((c) => (
                <li key={c.id}>
                  <button
                    type="button"
                    onClick={() => setSelectedId(c.id)}
                    aria-current={c.id === selectedId}
                    className={`flex w-full flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 text-left hover:bg-surface-raised ${c.id === selectedId ? 'bg-surface-raised' : ''}`}
                  >
                    <span className="font-medium">{c.name}</span>
                    {c.status === 'suspended' && <span className="text-xs text-late">Suspendida</span>}
                    <span className="ml-auto text-ink-muted tabular-nums">
                      {c.vehicles_count} {c.vehicles_count === 1 ? 'unidad' : 'unidades'} · {c.users_count} usuarios
                    </span>
                    {(c.vehicles_without_signal ?? 0) > 0 && (
                      <span className="w-full text-xs text-risk">{c.vehicles_without_signal} sin reportar en más de un día</span>
                    )}
                  </button>
                </li>
              ))}
              {companies?.length === 0 && <li className="px-3 py-2 text-ink-muted">Todavía no hay empresas.</li>}
            </ul>

            {creating ? (
              <CompanyForm
                submitLabel="Dar de alta"
                onCancel={() => setCreating(false)}
                onSubmit={async (data) => {
                  const company = await createCompany(data)
                  setCreating(false)
                  setSelectedId(company.id)
                  refresh()
                }}
              />
            ) : (
              <button type="button" onClick={() => setCreating(true)} className={`${primary} self-start`}>
                Nueva empresa
              </button>
            )}
          </div>
        </Panel>

        {selectedId !== null && (
          <CompanyDetail key={selectedId} companyId={selectedId} currentUserId={user.id} onChanged={refresh} />
        )}
      </main>
    </div>
  )
}

function CompanyDetail({ companyId, currentUserId, onChanged }: { companyId: number; currentUserId: number; onChanged: () => void }) {
  const [company, setCompany] = useState<AdminCompanyDetail | null>(null)
  const [editing, setEditing] = useState(false)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    fetchAdminCompany(companyId, controller.signal).then(setCompany).catch(() => undefined)
    return () => controller.abort()
  }, [companyId, reload])

  if (!company) return <p className="p-4 text-sm text-ink-muted">Cargando…</p>

  const changed = () => {
    setReload((n) => n + 1)
    onChanged()
  }

  async function viewAs() {
    await viewAsCompany(companyId)
    window.location.hash = '#/'
    window.location.reload()
  }

  return (
    <div className="flex min-w-0 flex-col gap-4">
      <Panel
        index={2}
        title={company.name}
        aside={
          <span className="flex gap-2">
            <button type="button" onClick={() => setEditing((v) => !v)} className={button}>
              {editing ? 'Cerrar' : 'Editar datos'}
            </button>
            <button type="button" onClick={viewAs} className={button} disabled={company.vehicles.length === 0}>
              Ver como este cliente
            </button>
          </span>
        }
      >
        <div className="p-4 text-sm">
          {editing ? (
            <CompanyForm
              initial={company}
              submitLabel="Guardar"
              onCancel={() => setEditing(false)}
              onSubmit={async (data) => {
                await updateCompany(companyId, data)
                setEditing(false)
                changed()
              }}
            />
          ) : (
            <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
              <Field label="Contacto" value={[company.contact_name, company.contact_email, company.contact_phone].filter(Boolean).join(' · ')} />
              <Field label="Razón social" value={[company.legal_name, company.rfc].filter(Boolean).join(' · ')} />
              <Field
                label="Plan"
                value={[company.plan, company.monthly_price !== null ? `$${company.monthly_price.toFixed(2)} por unidad al mes + IVA` : null]
                  .filter(Boolean)
                  .join(' · ')}
              />
              <Field label="Estado" value={company.status === 'active' ? 'Activa' : 'Suspendida'} />
              {company.notes && <Field label="Notas" value={company.notes} />}
            </dl>
          )}
        </div>
      </Panel>

      <Panel index={3} title="Unidades" aside={`${company.vehicles.length}`}>
        <VehiclesSection company={company} onChanged={changed} />
      </Panel>

      <Panel index={4} title="Usuarios" aside={`${company.users.length}`}>
        <div className="p-4">
          <UserManager
            users={company.users}
            currentUserId={currentUserId}
            onChanged={changed}
            actions={{ ...userActions, create: (data) => createAdminUser(companyId, data) }}
          />
        </div>
      </Panel>
    </div>
  )
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-ink-muted">{label}</dt>
      <dd>{value || '—'}</dd>
    </div>
  )
}

const SIGNAL: Record<SignalStatus, { label: string; className: string }> = {
  ok: { label: 'Reportando', className: 'text-ok' },
  no_signal: { label: 'Sin reportar', className: 'text-risk' },
  never: { label: 'Nunca ha reportado', className: 'text-late' },
}

function VehiclesSection({ company, onChanged }: { company: AdminCompanyDetail; onChanged: () => void }) {
  const [mode, setMode] = useState<'new' | 'existing'>('new')
  const [name, setName] = useState('')
  const [imei, setImei] = useState('')
  const [plate, setPlate] = useState('')
  const [unassigned, setUnassigned] = useState<AdminVehicle[]>([])
  const [existingId, setExistingId] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (mode !== 'existing') return
    const controller = new AbortController()
    fetchUnassignedVehicles(controller.signal).then(setUnassigned).catch(() => undefined)
    return () => controller.abort()
  }, [mode])

  async function run(task: () => Promise<unknown>) {
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

  async function submit(e: FormEvent) {
    e.preventDefault()
    const ok = await run(() =>
      mode === 'new'
        ? addCompanyVehicle(company.id, { name: name.trim(), imei: imei.trim(), plate: plate.trim() || null })
        : addCompanyVehicle(company.id, { vehicle_id: Number(existingId) }),
    )
    if (ok) {
      setName('')
      setImei('')
      setPlate('')
      setExistingId('')
    }
  }

  return (
    <div className="flex flex-col gap-4 p-4 text-sm">
      {error && (
        <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-late">
          {error}
        </p>
      )}

      <div className="-mx-4 overflow-x-auto px-4">
      <table className="w-full min-w-[560px] text-left">
        <thead className="text-ink-muted">
          <tr>
            <th className="px-3 py-2 font-normal">Unidad</th>
            <th className="px-3 py-2 font-normal">Placas</th>
            <th className="px-3 py-2 font-normal">IMEI</th>
            <th className="px-3 py-2 font-normal">Último reporte</th>
            <th className="px-3 py-2" />
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {company.vehicles.length === 0 && (
            <tr>
              <td colSpan={5} className="px-3 py-3 text-ink-muted">
                Sin unidades. Da de alta su equipo con el IMEI.
              </td>
            </tr>
          )}
          {company.vehicles.map((v) => (
            <tr key={v.id}>
              <td className="px-3 py-2">
                {v.name}
                {!v.active && <span className="ml-2 text-xs text-ink-muted">(inactiva)</span>}
              </td>
              <td className="px-3 py-2 text-ink-muted">{v.plate ?? '—'}</td>
              <td className="px-3 py-2 font-mono text-xs text-ink-muted">{v.imei ?? '—'}</td>
              <td className="px-3 py-2">
                <span className={SIGNAL[v.signal].className}>{SIGNAL[v.signal].label}</span>
                {v.last_report_at && <span className="ml-2 text-ink-muted">{formatLastSeen(v.last_report_at)}</span>}
              </td>
              <td className="px-3 py-2 text-right">
                <button
                  type="button"
                  disabled={busy}
                  className={`${button} hover:text-late`}
                  onClick={() => {
                    if (window.confirm(`¿Quitar ${v.name} de ${company.name}? Su historial se conserva.`)) {
                      run(() => updateAdminVehicle(v.id, { company_id: null }))
                    }
                  }}
                >
                  Quitar
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-3 border-t border-line pt-4">
        <div role="radiogroup" aria-label="Cómo dar de alta" className="flex gap-4">
          <label className="flex items-center gap-2">
            <input type="radio" checked={mode === 'new'} onChange={() => setMode('new')} />
            Equipo nuevo
          </label>
          <label className="flex items-center gap-2">
            <input type="radio" checked={mode === 'existing'} onChange={() => setMode('existing')} />
            Unidad que ya reporta sin empresa
          </label>
        </div>

        <div className="flex flex-wrap items-end gap-3">
          {mode === 'new' ? (
            <>
              <label className="flex flex-col gap-1">
                <span className="text-ink-muted">Nombre</span>
                <input required value={name} onChange={(e) => setName(e.target.value)} placeholder="Van 1" className={input} />
              </label>
              <label className="flex flex-col gap-1">
                <span className="text-ink-muted">IMEI (15 dígitos)</span>
                <input
                  required
                  inputMode="numeric"
                  pattern="\d{15}"
                  value={imei}
                  onChange={(e) => setImei(e.target.value)}
                  className={`${input} font-mono`}
                />
              </label>
              <label className="flex flex-col gap-1">
                <span className="text-ink-muted">Placas</span>
                <input value={plate} onChange={(e) => setPlate(e.target.value)} className={input} />
              </label>
            </>
          ) : (
            <label className="flex flex-col gap-1">
              <span className="text-ink-muted">Unidad</span>
              <select required value={existingId} onChange={(e) => setExistingId(e.target.value)} className={input}>
                <option value="">Elige una</option>
                {unassigned.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.name} {v.imei ? `· ${v.imei}` : ''}
                  </option>
                ))}
              </select>
            </label>
          )}
          <button type="submit" disabled={busy} className={primary}>
            Agregar unidad
          </button>
        </div>
        {mode === 'new' && (
          <p className="text-ink-muted">
            Se registra en Traccar con ese IMEI. Empieza a verse en cuanto el equipo esté configurado y mande su primera posición.
          </p>
        )}
      </form>
    </div>
  )
}

function CompanyForm({
  initial,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  initial?: AdminCompany
  submitLabel: string
  onSubmit: (data: CompanyInput) => Promise<void>
  onCancel: () => void
}) {
  const [data, setData] = useState<CompanyInput>({
    name: initial?.name ?? '',
    legal_name: initial?.legal_name ?? '',
    rfc: initial?.rfc ?? '',
    contact_name: initial?.contact_name ?? '',
    contact_email: initial?.contact_email ?? '',
    contact_phone: initial?.contact_phone ?? '',
    plan: initial?.plan ?? '',
    monthly_price: initial?.monthly_price ?? null,
    status: initial?.status ?? 'active',
    notes: initial?.notes ?? '',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const set = (key: keyof CompanyInput) => (value: string) => setData((d) => ({ ...d, [key]: value }))

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      // Los vacíos se mandan como null para no guardar textos en blanco
      const clean = Object.fromEntries(
        Object.entries(data).map(([k, v]) => [k, typeof v === 'string' && v.trim() === '' ? null : v]),
      ) as unknown as CompanyInput
      await onSubmit({ ...clean, name: data.name.trim() })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo guardar.')
    } finally {
      setBusy(false)
    }
  }

  const text = (key: keyof CompanyInput, label: string, extra: Record<string, string | boolean> = {}) => (
    <label className="flex flex-col gap-1">
      <span className="text-ink-muted">{label}</span>
      <input value={(data[key] as string | null) ?? ''} onChange={(e) => set(key)(e.target.value)} className={input} {...extra} />
    </label>
  )

  return (
    <form onSubmit={submit} className="flex flex-col gap-3 rounded-md border border-line p-3 text-sm">
      {error && <p className="text-late">{error}</p>}
      <div className="grid gap-3 sm:grid-cols-2">
        {text('name', 'Nombre comercial', { required: true })}
        {text('contact_name', 'Contacto')}
        {text('contact_email', 'Correo', { type: 'email' })}
        {text('contact_phone', 'Teléfono')}
        {text('legal_name', 'Razón social')}
        {text('rfc', 'RFC')}
        {text('plan', 'Plan')}
        <label className="flex flex-col gap-1">
          <span className="text-ink-muted">Precio por unidad al mes (sin IVA)</span>
          <input
            type="number"
            min="0"
            step="0.01"
            value={data.monthly_price ?? ''}
            onChange={(e) => setData((d) => ({ ...d, monthly_price: e.target.value === '' ? null : Number(e.target.value) }))}
            className={input}
          />
        </label>
        {initial && (
          <label className="flex flex-col gap-1">
            <span className="text-ink-muted">Estado</span>
            <select
              value={data.status}
              onChange={(e) => setData((d) => ({ ...d, status: e.target.value as 'active' | 'suspended' }))}
              className={input}
            >
              <option value="active">Activa</option>
              <option value="suspended">Suspendida</option>
            </select>
          </label>
        )}
      </div>
      <label className="flex flex-col gap-1">
        <span className="text-ink-muted">Notas</span>
        <textarea value={data.notes ?? ''} onChange={(e) => set('notes')(e.target.value)} rows={2} className={input} />
      </label>
      <div className="flex gap-2">
        <button type="submit" disabled={busy} className={primary}>
          {submitLabel}
        </button>
        <button type="button" onClick={onCancel} className={button}>
          Cancelar
        </button>
      </div>
    </form>
  )
}
