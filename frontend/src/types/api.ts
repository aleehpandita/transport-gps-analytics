// Tipos de la API de Laravel, alineados con la respuesta real de cada Resource.

// Tipos GeoJSON: vienen con @types/leaflet (dependencia @types/geojson)
import type { MultiPolygon, Polygon } from 'geojson'

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
  current_zone: Zone | null // zona de la última posición (punto, polígono o área)
  stopped_since: string | null // ISO 8601 en UTC; null si la última posición va en movimiento
}

// GET /api/vehicles/live: última posición de cada unidad, leída directo de Traccar
export interface LivePosition {
  vehicle_id: number
  latitude: number
  longitude: number
  speed: number // km/h
  course: number // grados, 0 = norte
  ignition: boolean | null
  valid: boolean
  device_time: string // ISO 8601 en UTC
}

export interface Zone {
  id: number
  name: string
}

// ZoneResource (GET /api/zones): la zona completa, con lo necesario para dibujarla
export interface MapZone extends Zone {
  region: string | null
  is_airport: boolean
  requires_ferry_transfer: boolean
  time_from_airport_minutes: number | null
  latitude: number | null
  longitude: number | null
  geofence_radius_km: number | null
  geometry_type: string | null // 'Point', 'Polygon', 'MultiPolygon'...
  geometry: Polygon | MultiPolygon | null // solo en zonas de polígono
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
  // Lugar del catálogo donde empezó y terminó (lobby, terminal), si fue en uno.
  // Vienen en GET /api/trips y en el historial; en otros endpoints pueden no venir.
  origin_place?: string | null
  destination_place?: string | null
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

// GET /api/me y POST /api/login: el usuario con sesión
export interface User {
  id: number
  name: string
  email: string
  role: 'admin' | 'client'
}

// GET /api/vehicles/{id}/route?date=...: el recorrido de un día para el historial.
// Cada posición es [timestamp en segundos, latitud, longitud, km/h, rumbo en grados].
export type RoutePoint = [number, number, number, number, number]

export interface RouteTrip extends Trip {
  origin_place: string | null // en el historial siempre vienen
  destination_place: string | null
}

export interface RouteDay {
  date: string // YYYY-MM-DD, día de Cancún
  points: RoutePoint[]
  trips: RouteTrip[]
}

// GET /api/reports/*: reportes por rango de fechas (horas en ISO 8601 UTC, fechas de Cancún)
export interface DailyReportRow {
  vehicle_id: number
  vehicle: string
  date: string // YYYY-MM-DD
  trips: number // sin contar movimientos de menos de 2 km
  km: number
  driving_seconds: number
  first_start: string
  last_end: string
  workday_seconds: number // de la primera salida a la última llegada
  stops: number
  stopped_seconds: number
  max_speed: number
}

export interface TripReportRow {
  id: number
  vehicle_id: number
  vehicle: string
  started_at: string
  ended_at: string
  duration_seconds: number
  distance_km: number
  max_speed: number | null
  origin_zone: string | null
  destination_zone: string | null
  origin_place: string | null
  destination_place: string | null
}

export interface StopReportRow {
  vehicle_id: number
  vehicle: string
  started_at: string
  ended_at: string
  duration_seconds: number
  place: string | null
  zone: string | null
}

// Viajes y paradas vienen paginados, con los totales de TODO el periodo (no de la página)
export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ReportPage<T, Totals> {
  data: T[]
  meta: PageMeta
  totals: Totals
}

export interface TripReportTotals {
  trips: number
  km: number
  duration_seconds: number
  short_trips: number // movimientos de menos de 2 km en el periodo, se muestren o no
}

export interface StopReportTotals {
  stops: number
  duration_seconds: number
}

export type TripsReport = ReportPage<TripReportRow, TripReportTotals>
export type StopsReport = ReportPage<StopReportRow, StopReportTotals>

// GET /api/events: eventos de las unidades, del más reciente al más viejo
export type VehicleEventType = 'zone_enter' | 'zone_exit' | 'place_visit' | 'overspeed' | 'long_stop' | 'signal_lost'

export interface VehicleEventRow {
  id: number
  vehicle_id: number
  vehicle: string
  type: VehicleEventType
  started_at: string // ISO 8601 UTC
  ended_at: string | null // null: sigue pasando (exceso, parada, sin señal, visita en curso)
  duration_seconds: number | null
  latitude: number
  longitude: number
  zone: string | null
  place: string | null
  value: number | null // velocidad máxima en un exceso
  data: Record<string, number> | null
}

export interface EventsPage {
  data: VehicleEventRow[]
  meta: PageMeta
  counts: Partial<Record<VehicleEventType, number>> // del periodo completo, sin el filtro de tipo
}