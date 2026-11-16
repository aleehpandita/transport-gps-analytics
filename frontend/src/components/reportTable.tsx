import type { LoadState } from '../lib/loadState'

// Piezas comunes de las tablas de reportes

export const th = 'px-3 py-2 text-left font-normal text-ink-muted whitespace-nowrap'
export const thNum = 'px-3 py-2 text-right font-normal text-ink-muted whitespace-nowrap'
export const td = 'px-3 py-2 whitespace-nowrap'
export const tdNum = 'px-3 py-2 text-right tabular-nums whitespace-nowrap'

// Estados comunes: cargando (la primera vez), error o sin datos
export function ReportStatus<T>({ state, isEmpty, empty }: { state: LoadState<T>; isEmpty: boolean; empty: string }) {
  if (state.status === 'loading' && state.last === undefined) return <p className="py-6 text-sm text-ink-muted">Cargando…</p>
  if (state.status === 'error') {
    return (
      <p role="alert" className="rounded-md border border-late/40 bg-late/10 px-3 py-2 text-sm text-late">
        No se pudo cargar el reporte ({state.message}). Revisa tu conexión y vuelve a intentar.
      </p>
    )
  }
  if (isEmpty) return <p className="py-6 text-sm text-ink-muted">{empty}</p>
  return null
}
