// Tipos de la API de Laravel, alineados con la respuesta real de cada Resource.

// PositionResource (también se usa en GET /api/vehicles/{id}/latest-position)
export interface LatestPosition {
  id: number
  latitude: number
  longitude: number
  speed: number // km/h: traccar:sync-positions ya convierte desde nudos
  course: number
  ignition: boolean | null
  motion: boolean | null
  valid: boolean
  device_time: string // ISO 8601 en UTC
  data_source: string // 'real' o 'synthetic'
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
  latest_position: LatestPosition | null
}

// TripResource
export interface Trip {
  id: number
  vehicle_id: number
  vehicle?: string // nombre de la unidad; solo viene en GET /api/trips
  started_at: string // ISO 8601 en UTC
  ended_at: string // ISO 8601 en UTC
  duration_seconds: number
  distance_km: number
  average_speed: number | null
  max_speed: number | null
  stops_count: number
  stopped_seconds: number
  origin_zone: string | null
  destination_zone: string | null
  data_source: string // 'real' o 'synthetic'
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