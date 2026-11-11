import { useEffect, useState } from 'react'
import { PageHeader } from './PageHeader'
import { Panel } from './Panel'
import { UserManager, type UserActions } from './UserManager'
import {
  createCompanyUser,
  deleteCompanyUser,
  fetchCompanyUsers,
  resetCompanyUserPassword,
  updateCompanyUser,
} from '../lib/apiClient'
import type { AccountUser, User } from '../types/api'

// El dueño de una empresa da de alta a su gente: operadores que confirman servicios y
// usuarios de solo consulta.

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
      <PageHeader title="Usuarios" current="users" user={user} onLogout={onLogout} />
      <main className="p-4 lg:p-6">
        <Panel index={1} title={user.company?.name ?? 'Tu empresa'} aside={users ? `${users.length} usuarios` : undefined}>
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
