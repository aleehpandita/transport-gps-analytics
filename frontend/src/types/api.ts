// Tipos de la API de Laravel, alineados con la respuesta real de cada Resource.

// Pendiente de confirmar con GET /api/vehicles/{id}/latest-position
export interface LatestPosition {
  latitude: number
  longitude: number
  speed_kmh: number
  ignition: boolean | null
  fix_time: string // ISO 8601 en UTC
}

// VehicleResource
export interface Vehicle {
  id: number
  name: string
  plate: string | null
  make: string | null
  model: string | null
  active: boolean
  distance_today_km: number
  latest_position?: LatestPosition | null // todavía no viene en /api/vehicles
}

// ScheduledServiceResource: vehicle y destination_zone llegan como nombres, no como objetos
export interface ScheduledService {
  id: number
  vehicle_id: number
  vehicle: string | null
  destination_zone: string | null
  scheduled_at: string // ISO 8601 en UTC
  notes: string | null
}

// ResourceCollection simple: { data: [...] }
export interface Collection<T> {
  data: T[]
}

// ResourceCollection paginada: { data, links, meta }
export interface Paginated<T> extends Collection<T> {
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}