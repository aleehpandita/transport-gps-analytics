import { useEffect, useState } from 'react'
import { fetchGeofences, fetchVehicles } from '../lib/apiClient'
import type { Geofence, User, Vehicle } from '../types/api'
import { AlertInbox } from './AlertInbox'
import { AlertRulesManager } from './AlertRulesManager'
import { GeofenceManager } from './GeofenceManager'
import { PageHeader } from './PageHeader'

// Alertas de la empresa en tres pestañas: la bandeja (lo que pasó), las alertas que
// configuró (qué vigilar) y sus geocercas (las zonas que dibujó).
// #/alertas, #/alertas/configurar y #/alertas/geocercas.

export type AlertsTab = 'inbox' | 'rules' | 'geofences'

const TABS: { tab: AlertsTab; href: string; label: string }[] = [
  { tab: 'inbox', href: '#/alertas', label: 'Bandeja' },
  { tab: 'rules', href: '#/alertas/configurar', label: 'Configurar alertas' },
  { tab: 'geofences', href: '#/alertas/geocercas', label: 'Geocercas' },
]

export function AlertsView({ user, tab, onLogout }: { user: User; tab: AlertsTab; onLogout: () => void }) {
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [geofences, setGeofences] = useState<Geofence[]>([])
  const [reloadFences, setReloadFences] = useState(0)

  // Un admin de EkMira primero elige de qué cliente con "Ver como"
  const noCompany = user.role === 'admin' && !user.acting_company

  useEffect(() => {
    if (noCompany) return
    const controller = new AbortController()
    fetchVehicles(controller.signal)
      .then(setVehicles)
      .catch(() => {})
    return () => controller.abort()
  }, [noCompany])

  useEffect(() => {
    if (noCompany) return
    const controller = new AbortController()
    fetchGeofences(controller.signal)
      .then(setGeofences)
      .catch(() => {})
    return () => controller.abort()
  }, [noCompany, reloadFences])

  return (
    <div className="flex min-h-screen flex-col">
      <PageHeader title="Alertas" current="alerts" user={user} onLogout={onLogout} />

      <nav aria-label="Secciones de alertas" className="flex flex-wrap gap-2 border-b border-line px-4 py-2 text-sm lg:px-6">
        {TABS.map((t) =>
          t.tab === tab ? (
            <span key={t.tab} aria-current="page" className="rounded-md bg-surface-raised px-3 py-1.5 font-medium">
              {t.label}
            </span>
          ) : (
            <a key={t.tab} href={t.href} className="rounded-md px-3 py-1.5 text-ink-muted hover:bg-surface-raised hover:text-ink">
              {t.label}
            </a>
          ),
        )}
      </nav>

      <main className="flex-1 p-4 lg:p-6">
        {noCompany ? (
          <p className="rounded-md border border-line px-4 py-3 text-sm text-ink-muted">
            Las alertas son de cada cliente. Entra a <a href="#/clientes" className="text-ok underline">Clientes</a> y usa
            «Ver como» para ver o configurar las de uno.
          </p>
        ) : tab === 'inbox' ? (
          <AlertInbox vehicles={vehicles} />
        ) : tab === 'rules' ? (
          <AlertRulesManager user={user} vehicles={vehicles} geofences={geofences} />
        ) : (
          <GeofenceManager user={user} geofences={geofences} onChanged={() => setReloadFences((n) => n + 1)} />
        )}
      </main>
    </div>
  )
}
