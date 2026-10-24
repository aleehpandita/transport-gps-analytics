import type { PageMeta } from '../types/api'

// "101 a 200 de 742" con Anterior y Siguiente. Solo aparece si hay más de una página.
export function Pagination({
  meta,
  busy,
  noun,
  onPage,
}: {
  meta: PageMeta
  busy: boolean
  noun: string
  onPage: (page: number) => void
}) {
  if (meta.last_page <= 1) return null

  const first = (meta.current_page - 1) * meta.per_page + 1
  const last = Math.min(meta.current_page * meta.per_page, meta.total)
  const button =
    'rounded-md border border-line px-3 py-1.5 hover:bg-surface-raised disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent'

  return (
    <nav aria-label="Páginas" className="mt-4 flex flex-wrap items-center gap-3 text-sm">
      <span className="text-ink-muted tabular-nums">
        {first.toLocaleString('es-MX')} a {last.toLocaleString('es-MX')} de {meta.total.toLocaleString('es-MX')} {noun}
      </span>
      <div className="ml-auto flex items-center gap-2">
        <button
          type="button"
          className={button}
          disabled={busy || meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
        >
          Anterior
        </button>
        <span className="px-1 text-ink-muted tabular-nums">
          Página {meta.current_page} de {meta.last_page}
        </span>
        <button
          type="button"
          className={button}
          disabled={busy || meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
        >
          Siguiente
        </button>
      </div>
    </nav>
  )
}