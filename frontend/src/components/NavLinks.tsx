// Navegación entre las páginas del panel. La página actual se marca y no es enlace.

export type Page = 'live' | 'history' | 'reports' | 'events'

const LINKS: { page: Page; href: string; label: string; icon: string }[] = [
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
]

export function NavLinks({ current }: { current: Page }) {
  return (
    <nav aria-label="Secciones" className="flex flex-wrap items-center gap-2">
      {LINKS.map((link) => {
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