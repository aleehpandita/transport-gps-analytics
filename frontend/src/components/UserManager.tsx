import { useState, type FormEvent } from 'react'
import { COMPANY_ROLES, ROLE_LABEL } from '../lib/roles'
import type { AccountUser, CompanyRole } from '../types/api'

// Alta y administración de usuarios de una empresa. Lo usan el panel de EkMira (cualquier
// empresa) y el dueño de cada empresa (la suya); cambia solo a qué API llama.

export interface UserActions {
  create: (input: { name: string; email: string; role: CompanyRole }) => Promise<AccountUser>
  update: (id: number, input: { role: CompanyRole }) => Promise<AccountUser>
  resetPassword: (id: number) => Promise<AccountUser>
  remove: (id: number) => Promise<unknown>
}

interface UserManagerProps {
  users: AccountUser[]
  actions: UserActions
  currentUserId: number
  onChanged: () => void
}

const input = 'rounded-md border border-line bg-surface-raised px-3 py-1.5'
const button = 'rounded-md border border-line px-2.5 py-1 text-ink-muted hover:bg-surface-raised disabled:opacity-60'

export function UserManager({ users, actions, currentUserId, onChanged }: UserManagerProps) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<CompanyRole>('operator')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // Contraseña temporal recién generada: se muestra una sola vez para entregarla
  const [issued, setIssued] = useState<{ email: string; password: string } | null>(null)

  async function run(task: () => Promise<AccountUser | unknown>) {
    setBusy(true)
    setError(null)
    try {
      const result = await task()
      const user = result as AccountUser | undefined
      if (user?.temporary_password) setIssued({ email: user.email, password: user.temporary_password })
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
    const ok = await run(() => actions.create({ name: name.trim(), email: email.trim(), role }))
    if (ok) {
      setName('')
      setEmail('')
    }
  }

  return (
    <div className="flex flex-col gap-4 text-sm">
      {issued && (
        <div role="status" className="rounded-md border border-ok/50 bg-ok/10 px-4 py-3">
          <p>
            Contraseña temporal de <strong>{issued.email}</strong>:{' '}
            <code className="rounded bg-surface-raised px-2 py-0.5 font-mono text-base select-all">{issued.password}</code>
          </p>
          <p className="mt-1 text-ink-muted">Cópiala y entrégala ahora: no se vuelve a mostrar.</p>
          <button type="button" onClick={() => setIssued(null)} className={`${button} mt-2`}>
            Ya la copié
          </button>
        </div>
      )}

      {error && (
        <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-late">
          {error}
        </p>
      )}

      <div className="-mx-4 overflow-x-auto px-4">
      <table className="w-full min-w-[560px] text-left">
        <thead className="text-ink-muted">
          <tr>
            <th className="px-3 py-2 font-normal">Nombre</th>
            <th className="px-3 py-2 font-normal">Correo</th>
            <th className="px-3 py-2 font-normal">Rol</th>
            <th className="px-3 py-2" />
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {users.length === 0 && (
            <tr>
              <td colSpan={4} className="px-3 py-3 text-ink-muted">
                Todavía no hay usuarios.
              </td>
            </tr>
          )}
          {users.map((u) => {
            const self = u.id === currentUserId
            return (
              <tr key={u.id}>
                <td className="px-3 py-2">{u.name}</td>
                <td className="px-3 py-2 text-ink-muted">{u.email}</td>
                <td className="px-3 py-2">
                  {self || u.role === 'admin' ? (
                    ROLE_LABEL[u.role]
                  ) : (
                    <select
                      value={u.role}
                      disabled={busy}
                      aria-label={`Rol de ${u.name}`}
                      onChange={(e) => run(() => actions.update(u.id, { role: e.target.value as CompanyRole }))}
                      className={input}
                    >
                      {COMPANY_ROLES.map((r) => (
                        <option key={r.role} value={r.role}>
                          {r.label}
                        </option>
                      ))}
                    </select>
                  )}
                </td>
                <td className="px-3 py-2 text-right whitespace-nowrap">
                  <button type="button" disabled={busy} onClick={() => run(() => actions.resetPassword(u.id))} className={button}>
                    Nueva contraseña
                  </button>
                  {!self && (
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => {
                        if (window.confirm(`¿Eliminar a ${u.name}? Ya no podrá entrar al panel.`)) run(() => actions.remove(u.id))
                      }}
                      className={`${button} ml-2 hover:text-late`}
                    >
                      Eliminar
                    </button>
                  )}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
      </div>

      <form onSubmit={submit} className="flex flex-wrap items-end gap-3 border-t border-line pt-4">
        <label className="flex flex-col gap-1">
          <span className="text-ink-muted">Nombre</span>
          <input required value={name} onChange={(e) => setName(e.target.value)} className={input} />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-ink-muted">Correo</span>
          <input required type="email" value={email} onChange={(e) => setEmail(e.target.value)} className={input} />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-ink-muted">Rol</span>
          <select value={role} onChange={(e) => setRole(e.target.value as CompanyRole)} className={input}>
            {COMPANY_ROLES.map((r) => (
              <option key={r.role} value={r.role}>
                {r.label}: {r.help}
              </option>
            ))}
          </select>
        </label>
        <button
          type="submit"
          disabled={busy}
          className="rounded-md border border-ok/50 bg-ok/15 px-3 py-1.5 font-medium text-ok hover:bg-ok/25 disabled:opacity-60"
        >
          Dar de alta
        </button>
      </form>
    </div>
  )
}
