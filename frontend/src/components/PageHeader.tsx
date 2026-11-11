import { NavLinks, type Page } from './NavLinks'
import type { User } from '../types/api'

// Encabezado de las páginas de administración: título, navegación y salir
export function PageHeader({ title, current, user, onLogout }: { title: string; current: Page; user: User; onLogout: () => void }) {
  return (
    <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 lg:px-6">
      <h1 className="text-lg font-semibold">{title}</h1>
      <div className="ml-auto flex flex-wrap items-center gap-3 text-sm">
        <NavLinks current={current} user={user} />
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
  )
}
