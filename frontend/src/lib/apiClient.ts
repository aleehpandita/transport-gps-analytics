import type { Collection, Paginated, ScheduledService, Trip, Vehicle } from '../types/api'

export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

export async function getJson<T>(path: string, signal?: AbortSignal): Promise<T> {
  const res = await fetch(`/api${path}`, {
    headers: { Accept: 'application/json' },
    signal,
  })

  if (!res.ok) {
    throw new ApiError(res.status, `GET /api${path} respondió ${res.status}`)
  }

  return res.json() as Promise<T>
}

// Fetchers estables (nivel de módulo) para usarlos con usePolling sin re-crear el efecto
export const fetchVehicles = (signal: AbortSignal) =>
  getJson<Collection<Vehicle>>('/vehicles', signal).then((r) => r.data)

// Servicios desde 1 h atrás (pueden seguir en curso) en adelante, los más próximos primero.
// El backend filtra y ordena; aquí solo se pide la ventana y el tope.
const UPCOMING_LOOKBACK_MS = 60 * 60 * 1000
const UPCOMING_LIMIT = 12

export const fetchScheduledServices = (signal: AbortSignal) => {
  const params = new URLSearchParams({
    from: new Date(Date.now() - UPCOMING_LOOKBACK_MS).toISOString(),
    per_page: String(UPCOMING_LIMIT),
  })

  return getJson<Paginated<ScheduledService>>(`/scheduled-services?${params}`, signal).then(
    (r) => r.data,
  )
}

// Viajes de hoy de toda la flota. El backend corta el día en hora Cancún.
export const fetchTodayTrips = (signal: AbortSignal) =>
  getJson<Collection<Trip>>('/trips', signal).then((r) => r.data)