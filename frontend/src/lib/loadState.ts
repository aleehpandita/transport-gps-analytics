// Estado de una carga que se repite (cambiar de página, de filtros). Mientras carga lo nuevo
// se conserva lo anterior, para mostrarlo atenuado y que la tabla no brinque.

export type LoadState<T> = { status: 'loading'; last?: T } | { status: 'error'; message: string } | { status: 'ready'; value: T }

export function loadingFrom<T>(state: LoadState<T>): LoadState<T> {
  if (state.status === 'ready') return { status: 'loading', last: state.value }
  if (state.status === 'loading') return state
  return { status: 'loading' }
}

// Lo que se puede dibujar ahora: lo listo, o lo anterior mientras carga
export function shown<T>(state: LoadState<T>): T | undefined {
  return state.status === 'ready' ? state.value : state.status === 'loading' ? state.last : undefined
}