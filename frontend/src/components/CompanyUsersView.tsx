import { useEffect, useState } from 'react'
import { PageHeader } from './PageHeader'
import { Panel } from './Panel'
import { UserManager, type UserActions } from './UserManager'
import { VehicleEditRow } from './VehicleEditRow'
import {
  createCompanyUser,
  deleteCompanyUser,
  fetchCompanyUsers,
  fetchCompanyVehicles,
  resetCompanyUserPassword,
  updateCompanyUser,
  updateCompanyVehicle,
  type VehicleEditInput,
} from '../lib/apiClient'
import { formatLastSeen } from '../lib/format'
import { VEHICLE_SIGNAL } from '../lib/vehicleIcon'
import type { AccountUser, AdminVehicle, User } from '../types/api'

// Mi empresa: el dueño arregla sus unidades (nombre, placas, ícono y color en el mapa) y da
// de alta a su gente: operadores que confirman servicios y usuarios de solo consulta.

const actions: UserActions = {
  create: createCompanyUser,
  update: updateCompanyUser,
  resetPassword: resetCompanyUserPassword,
  remove: deleteCompanyUser,
}

export function CompanyUsersView({ user, onLogout }: { user: User; onLogout: () => void }) {
  const [users, setUsers] = useState<AccountUser[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    fetchCompanyUsers(controller.signal)
      .then(setUsers)
      .catch((e) => {
        if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'No se pudo cargar.')
      })
    return () => controller.abort()
  }, [reload])

  return (
    <div className="flex min-h-screen flex-col">
      <PageHeader title="Mi empresa" current="users" user={user} onLogout={onLogout} />
      <main className="flex flex-col gap-4 p-4 lg:p-6">
        <CompanyVehicles />

        <Panel index={2} title="Usuarios" aside={users ? `${users.length}` : undefined}>
          <div className="p-4">
            {error && <p className="text-late">{error}</p>}
            {users && (
              <UserManager users={users} actions={actions} currentUserId={user.id} onChanged={() => setReload((n) => n + 1)} />
            )}
          </div>
        </Panel>
      </main>
    </div>
  )
}

function CompanyVehicles() {
  const [vehicles, setVehicles] = useState<AdminVehicle[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    fetchCompanyVehicles(controller.signal)
      .then(setVehicles)
      .catch((e) => {
        if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'No se pudo cargar.')
      })
    return () => controller.abort()
  }, [reload])

  async function save(id: number, input: VehicleEditInput) {
    setBusy(true)
    setError(null)
    try {
      await updateCompanyVehicle(id, input)
      setReload((n) => n + 1)
      return true
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar.')
      return false
    } finally {
      setBusy(false)
    }
  }

  return (
    <Panel index={1} title="Unidades" aside={vehicles ? `${vehicles.length}` : undefined}>
      <div className="flex flex-col gap-3 p-4 text-sm">
        {error && (
          <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-late">
            {error}
          </p>
        )}
        <p className="text-ink-muted">Con "Editar" cambias el nombre, las placas y cómo se ve cada unidad en el mapa.</p>

        <div className="-mx-4 overflow-x-auto px-4">
          <table className="w-full min-w-[480px] text-left">
            <thead className="text-ink-muted">
              <tr>
                <th className="px-3 py-2 font-normal">Unidad</th>
                <th className="px-3 py-2 font-normal">Placas</th>
                <th className="px-3 py-2 font-normal">Último reporte</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {vehicles?.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-3 py-3 text-ink-muted">
                    Todavía no hay unidades. EkMira las da de alta al instalar el equipo.
                  </td>
                </tr>
              )}
              {vehicles?.map((v) => (
                <VehicleEditRow key={v.id} vehicle={v} busy={busy} columns={4} onSave={(input) => save(v.id, input)}>
                  <td className="px-3 py-2 text-ink-muted">{v.plate ?? '—'}</td>
                  <td className="px-3 py-2">
                    <span className={VEHICLE_SIGNAL[v.signal].className}>{VEHICLE_SIGNAL[v.signal].label}</span>
                    {v.last_report_at && <span className="ml-2 text-ink-muted">{formatLastSeen(v.last_report_at)}</span>}
                  </td>
                </VehicleEditRow>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </Panel>
  )
}
