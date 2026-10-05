import { useEffect, useState } from 'react'

export interface PollState<T> {
  data: T | null
  error: string | null
  loading: boolean
  updatedAt: Date | null
}

// Tiempo máximo de espera por petición. Siempre queda por debajo del intervalo,
// para que una petición colgada se reporte antes de que arranque la siguiente.
const MAX_TIMEOUT_MS = 10_000

export function usePolling<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  intervalMs: number,
): PollState<T> {
  const [state, setState] = useState<PollState<T>>({
    data: null,
    error: null,
    loading: true,
    updatedAt: null,
  })

  useEffect(() => {
    let current: AbortController | null = null
    const timeoutMs = Math.min(MAX_TIMEOUT_MS, Math.floor(intervalMs * 0.8))

    const run = async () => {
      current?.abort('superseded')
      const controller = new AbortController()
      current = controller
      const timeoutId = window.setTimeout(() => controller.abort('timeout'), timeoutMs)

      try {
        const data = await fetcher(controller.signal)
        setState({ data, error: null, loading: false, updatedAt: new Date() })
      } catch (err) {
        const reason = controller.signal.reason
        if (controller.signal.aborted && reason !== 'timeout') return

        const message =
          reason === 'timeout'
            ? `La API no respondió en ${timeoutMs / 1000} s`
            : err instanceof Error
              ? err.message
              : 'Error desconocido'

        setState((prev) => ({ ...prev, loading: false, error: message }))
      } finally {
        window.clearTimeout(timeoutId)
      }
    }

    run()
    const id = window.setInterval(run, intervalMs)

    return () => {
      window.clearInterval(id)
      current?.abort('unmount')
    }
  }, [fetcher, intervalMs])

  return state
}