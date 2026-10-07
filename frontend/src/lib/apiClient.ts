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

// Solo lee la primera página (50 registros). Más adelante filtraremos en el backend.
export const fetchScheduledServices = (signal: AbortSignal) =>
  getJson<Paginated<ScheduledService>>('/scheduled-services', signal).then((r) => r.data)

// Viajes de hoy de toda la flota. El backend corta el día en hora Cancún.
export const fetchTodayTrips = (signal: AbortSignal) =>
  getJson<Collection<Trip>>('/trips', signal).then((r) => r.data)