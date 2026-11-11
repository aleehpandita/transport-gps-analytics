// Navegación entre las páginas del panel. La página actual se marca y no es enlace.
// Los enlaces de administración dependen del rol: Clientes para el equipo de EkMira,
// Usuarios para el dueño de una empresa.

import { useState } from 'react'
import { viewAsCompany } from '../lib/apiClient'
import type { User } from '../types/api'

export type Page = 'live' | 'history' | 'operation' | 'reports' | 'events' | 'clients' | 'users'

interface NavLink {
  page: Page
  href: string
  label: string
  icon: string
  visible?: (user: User) => boolean
}

const LINKS: NavLink[] = [
  {
    page: 'live',
    href: '#/',
    label: 'Mapa en vivo',
    // Pin de ubicación
    icon: 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z M12 7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z',
  },
  {
    page: 'history',
    href: '#/historial',
    label: 'Historial',
    // Reloj con flecha hacia atrás
    icon: 'M3 12a9 9 0 1 0 3-6.7L3 8 M3 3v5h5 M12 7v5l3 2',
  },
  {
    page: 'operation',
    href: '#/operacion',
    label: 'Operación',
    // Tablilla con lista
    icon: 'M9 4h6v3H9z M9 5H6v16h12V5h-3 M9 11h6 M9 15h6',
  },
  {
    page: 'reports',
    href: '#/reportes',
    label: 'Reportes',
    // Hoja con barras
    icon: 'M4 20V10 M10 20V4 M16 20v-7 M22 20H2',
  },
  {
    page: 'events',
    href: '#/eventos',
    label: 'Eventos',
    // Campana
    icon: 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9 M10.3 21a1.9 1.9 0 0 0 3.4 0',
  },
  {
    page: 'clients',
    href: '#/clientes',
    label: 'Clientes',
    // Edificio
    icon: 'M4 21V5l8-3v19 M12 21h8V9l-8-3 M7 9h2 M7 13h2 M7 17h2 M15 13h2 M15 17h2',
    visible: (user) => user.role === 'admin',
  },
  {
    page: 'users',
    href: '#/usuarios',
    label: 'Usuarios',
    // Personas
    icon: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.9 M16 3.1a4 4 0 0 1 0 7.8',
    visible: (user) => user.role === 'owner',
  },
]

export function NavLinks({ current, user }: { current: Page; user?: User }) {
  const links = LINKS.filter((link) => !link.visible || (user && link.visible(user)))

  return (
    <nav aria-label="Secciones" className="flex flex-wrap items-center gap-2">
      {user?.acting_company && <ViewingAs name={user.acting_company.name} />}
      {links.map((link) => {
        const active = link.page === current
        const icon = (
          <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d={link.icon} />
          </svg>
        )

        return active ? (
          <span
            key={link.page}
            aria-current="page"
            className="inline-flex items-center gap-2 rounded-md bg-surface-raised px-3 py-1.5 font-medium"
          >
            {icon}
            {link.label}
          </span>
        ) : (
          <a
            key={link.page}
            href={link.href}
            className="inline-flex items-center gap-2 rounded-md border border-ok/50 bg-ok/15 px-3 py-1.5 font-medium text-ok transition-colors hover:bg-ok/25 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ok"
          >
            {icon}
            {link.label}
          </a>
        )
      })}
    </nav>
  )
}
// Un admin viendo el panel como un cliente: siempre visible para no confundirse de empresa
function ViewingAs({ name }: { name: string }) {
  const [busy, setBusy] = useState(false)

  async function stop() {
    setBusy(true)
    try {
      await viewAsCompany(null)
    } finally {
      window.location.hash = '#/clientes'
      window.location.reload()
    }
  }

  return (
    <span className="inline-flex items-center gap-2 rounded-md border border-risk/50 bg-risk/15 px-3 py-1.5 text-risk">
      Viendo como <strong className="font-semibold">{name}</strong>
      <button type="button" onClick={stop} disabled={busy} className="underline hover:no-underline disabled:opacity-60">
        Salir
      </button>
    </span>
  )
}
