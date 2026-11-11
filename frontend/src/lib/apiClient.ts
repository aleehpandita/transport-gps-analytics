import type {
  AdminCompany,
  AdminCompanyDetail,
  AdminVehicle,
  AccountUser,
  CompanyRole,
  Collection,
  DailyReportRow,
  EventsPage,
  OperationDay,
  ReviewStatus,
  TripReview,
  VehicleEventType,
  StopsReport,
  TripsReport,
  LivePosition,
  MapZone,
  Paginated,
  RouteDay,
  ScheduledService,
  Trip,
  User,
  Vehicle,
} from '../types/api'

export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

// Se dispara cuando la API responde 401 o 419: la sesión ya no existe o expiró.
// App.tsx lo escucha y regresa a la pantalla de login.
export const AUTH_EXPIRED_EVENT = 'auth:expired'

function notifyIfSessionExpired(status: number): void {
  if (status === 401 || status === 419) {
    window.dispatchEvent(new Event(AUTH_EXPIRED_EVENT))
  }
}

// El panel y la API van en el mismo dominio (proxy de Vite en desarrollo, Nginx en
// producción), así que la cookie de sesión viaja sola con credentials: 'same-origin'.
export async function getJson<T>(path: string, signal?: AbortSignal): Promise<T> {
  const res = await fetch(`/api${path}`, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    signal,
  })

  if (!res.ok) {
    notifyIfSessionExpired(res.status)
    throw new ApiError(res.status, `GET /api${path} respondió ${res.status}`)
  }

  return res.json() as Promise<T>
}

// Sanctum protege los POST con CSRF: primero entrega la cookie XSRF-TOKEN y el panel la
// regresa en el encabezado X-XSRF-TOKEN. La cookie no es HttpOnly justo para poder leerla.
function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function ensureCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
}

async function postJson<T>(path: string, body: unknown = {}): Promise<T> {
  return sendJson<T>('POST', path, body)
}

async function sendJson<T>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body: unknown = {}): Promise<T> {
  await ensureCsrfCookie()

  const res = await fetch(`/api${path}`, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') ?? '',
    },
    body: JSON.stringify(body),
  })

  if (!res.ok) {
    // 422: error de validación de Laravel; se muestra su primer mensaje tal cual
    if (res.status === 422) {
      const payload = (await res.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null
      const first = payload?.errors ? Object.values(payload.errors)[0]?.[0] : undefined
      throw new ApiError(422, first ?? payload?.message ?? 'Revisa los datos.')
    }

    // En el login un 401 no es "sesión expirada", es simplemente que no hay sesión todavía
    if (path !== '/login') notifyIfSessionExpired(res.status)
    // 403 y 422 de abort() traen un mensaje para mostrar tal cual
    if (res.status === 403 || res.status === 422) {
      const payload = (await res.json().catch(() => null)) as { message?: string } | null
      if (payload?.message) throw new ApiError(res.status, payload.message)
    }
    throw new ApiError(res.status, `${method} /api${path} respondió ${res.status}`)
  }

  return res.json() as Promise<T>
}

// Sesión
export const login = (email: string, password: string) =>
  postJson<{ data: User }>('/login', { email, password }).then((r) => r.data)

export const logout = () => postJson<{ message: string }>('/logout')

export const fetchMe = (signal?: AbortSignal) => getJson<{ data: User }>('/me', signal).then((r) => r.data)

// Fetchers estables (nivel de módulo) para usarlos con usePolling sin re-crear el efecto
export const fetchVehicles = (signal: AbortSignal) => getJson<Collection<Vehicle>>('/vehicles', signal).then((r) => r.data)

// Posición en vivo de cada unidad, directo de Traccar (sin pasar por la base). Solo para el mapa.
export const fetchLiveVehicles = (signal: AbortSignal) =>
  getJson<Collection<LivePosition>>('/vehicles/live', signal).then((r) => r.data)

// Servicios desde 1 h atrás (pueden seguir en curso) en adelante, los más próximos primero.
// El backend filtra y ordena; aquí solo se pide la ventana y el tope.
const UPCOMING_LOOKBACK_MS = 60 * 60 * 1000
const UPCOMING_LIMIT = 12

export const fetchScheduledServices = (signal: AbortSignal) => {
  const params = new URLSearchParams({
    from: new Date(Date.now() - UPCOMING_LOOKBACK_MS).toISOString(),
    per_page: String(UPCOMING_LIMIT),
  })

  return getJson<Paginated<ScheduledService>>(`/scheduled-services?${params}`, signal).then((r) => r.data)
}

// Zonas activas con su geometría, para dibujarlas en el mapa
export const fetchZones = (signal: AbortSignal) => getJson<Collection<MapZone>>('/zones', signal).then((r) => r.data)

// Recorrido completo de una unidad en un día de Cancún (YYYY-MM-DD), para el historial
export const fetchVehicleRoute = (vehicleId: number, date: string, signal?: AbortSignal) =>
  getJson<{ data: RouteDay }>(`/vehicles/${vehicleId}/route?date=${encodeURIComponent(date)}`, signal).then((r) => r.data)

// Reportes por rango de fechas de Cancún (YYYY-MM-DD). vehicleId null = todas las unidades.
export interface ReportFilters {
  from: string
  to: string
  vehicleId: number | null
}

export type ReportKind = 'daily' | 'trips' | 'stops'

// Página y filtro de km mínimos; solo aplican a viajes y paradas
export interface ReportPageOptions {
  page?: number
  minKm?: number
}

function reportPath(kind: ReportKind, filters: ReportFilters, options: ReportPageOptions = {}, format?: 'csv'): string {
  const params = new URLSearchParams({ from: filters.from, to: filters.to })
  if (filters.vehicleId !== null) params.set('vehicle_id', String(filters.vehicleId))
  if (options.page && options.page > 1) params.set('page', String(options.page))
  if (options.minKm && options.minKm > 0) params.set('min_km', String(options.minKm))
  if (format) params.set('format', format)
  return `/reports/${kind}?${params}`
}

export const fetchDailyReport = (filters: ReportFilters, signal?: AbortSignal) =>
  getJson<Collection<DailyReportRow>>(reportPath('daily', filters), signal).then((r) => r.data)

export const fetchTripsReport = (filters: ReportFilters, options: ReportPageOptions, signal?: AbortSignal) =>
  getJson<TripsReport>(reportPath('trips', filters, options), signal)

export const fetchStopsReport = (filters: ReportFilters, options: ReportPageOptions, signal?: AbortSignal) =>
  getJson<StopsReport>(reportPath('stops', filters, options), signal)

// Dirección de descarga del CSV: un enlace normal, la cookie de sesión viaja sola.
// El CSV trae todo el periodo, sin paginar; solo respeta el filtro de km.
export const reportCsvUrl = (kind: ReportKind, filters: ReportFilters, minKm = 0) =>
  `/api${reportPath(kind, filters, { minKm }, 'csv')}`

// Eventos por periodo. type null = todos; 'alerts' = exceso, parada larga y sin señal.
export type EventFilter = VehicleEventType | 'alerts' | null

export function fetchEvents(filters: ReportFilters, type: EventFilter, page: number, signal?: AbortSignal) {
  const params = new URLSearchParams({ from: filters.from, to: filters.to })
  if (filters.vehicleId !== null) params.set('vehicle_id', String(filters.vehicleId))
  if (type) params.set('type', type)
  if (page > 1) params.set('page', String(page))
  return getJson<EventsPage>(`/events?${params}`, signal)
}

// Operación de un día de Cancún (YYYY-MM-DD) armada con GPS. vehicleId null = todas.
export function fetchOperation(date: string, vehicleId: number | null, signal?: AbortSignal) {
  const params = new URLSearchParams({ date })
  if (vehicleId !== null) params.set('vehicle_id', String(vehicleId))
  return getJson<{ data: OperationDay }>(`/operations?${params}`, signal).then((r) => r.data)
}

// Confirmar qué fue un viaje. 'clear' borra la confirmación y vuelve a lo que diga el GPS.
export const reviewTrip = (tripId: number, status: ReviewStatus | 'clear', note: string | null) =>
  postJson<{ data: TripReview | null }>('/trip-reviews', { trip_id: tripId, status, note }).then((r) => r.data)

// Alta de un lugar desde el panel (solo administradores)
export const createPlace = (place: { name: string; latitude: number; longitude: number; type?: string; radius_m?: number }) =>
  postJson<{ data: { id: number; name: string; type: string; zone: string | null } }>('/places', place).then((r) => r.data)

// Viajes de hoy de las unidades del usuario. El backend corta el día en hora Cancún.
export const fetchTodayTrips = (signal: AbortSignal) => getJson<Collection<Trip>>('/trips', signal).then((r) => r.data)
// Panel de EkMira: empresas, unidades y usuarios de todos los clientes (solo admins)
export const fetchAdminCompanies = (signal?: AbortSignal) =>
  getJson<Collection<AdminCompany>>('/admin/companies', signal).then((r) => r.data)

export const fetchAdminCompany = (id: number, signal?: AbortSignal) =>
  getJson<{ data: AdminCompanyDetail }>(`/admin/companies/${id}`, signal).then((r) => r.data)

export interface CompanyInput {
  name: string
  legal_name?: string | null
  rfc?: string | null
  contact_name?: string | null
  contact_email?: string | null
  contact_phone?: string | null
  plan?: string | null
  monthly_price?: number | null
  status?: 'active' | 'suspended'
  notes?: string | null
}

export const createCompany = (input: CompanyInput) =>
  postJson<{ data: AdminCompany }>('/admin/companies', input).then((r) => r.data)

export const updateCompany = (id: number, input: Partial<CompanyInput>) =>
  sendJson<{ data: AdminCompany }>('PATCH', `/admin/companies/${id}`, input).then((r) => r.data)

export const fetchUnassignedVehicles = (signal?: AbortSignal) =>
  getJson<Collection<AdminVehicle>>('/admin/vehicles?unassigned=1', signal).then((r) => r.data)

// Alta de unidad: {vehicle_id} asigna una que ya existe; {name, imei, plate} registra un equipo nuevo en Traccar
export const addCompanyVehicle = (
  companyId: number,
  input: { vehicle_id: number } | { name: string; imei: string; plate?: string | null },
) => postJson<{ data: AdminVehicle }>(`/admin/companies/${companyId}/vehicles`, input).then((r) => r.data)

export const updateAdminVehicle = (
  id: number,
  input: Partial<{ name: string; plate: string | null; make: string | null; model: string | null; active: boolean; company_id: number | null }>,
) => sendJson<{ data: AdminVehicle }>('PATCH', `/admin/vehicles/${id}`, input).then((r) => r.data)

export const createAdminUser = (companyId: number, input: { name: string; email: string; role: CompanyRole }) =>
  postJson<{ data: AccountUser }>(`/admin/companies/${companyId}/users`, input).then((r) => r.data)

export const updateAdminUser = (id: number, input: { name?: string; role?: CompanyRole }) =>
  sendJson<{ data: AccountUser }>('PATCH', `/admin/users/${id}`, input).then((r) => r.data)

export const resetAdminUserPassword = (id: number) =>
  postJson<{ data: AccountUser }>(`/admin/users/${id}/reset-password`).then((r) => r.data)

export const deleteAdminUser = (id: number) => sendJson<{ message: string }>('DELETE', `/admin/users/${id}`)

// El admin ve el panel como una empresa; null regresa a ver todo
export const viewAsCompany = (companyId: number | null) =>
  postJson<{ data: { acting_company_id: number | null } }>('/admin/view-as', { company_id: companyId })

// El dueño de una empresa administra a sus usuarios
export const fetchCompanyUsers = (signal?: AbortSignal) =>
  getJson<Collection<AccountUser>>('/company/users', signal).then((r) => r.data)

export const createCompanyUser = (input: { name: string; email: string; role: CompanyRole }) =>
  postJson<{ data: AccountUser }>('/company/users', input).then((r) => r.data)

export const updateCompanyUser = (id: number, input: { name?: string; role?: CompanyRole }) =>
  sendJson<{ data: AccountUser }>('PATCH', `/company/users/${id}`, input).then((r) => r.data)

export const resetCompanyUserPassword = (id: number) =>
  postJson<{ data: AccountUser }>(`/company/users/${id}/reset-password`).then((r) => r.data)

export const deleteCompanyUser = (id: number) => sendJson<{ message: string }>('DELETE', `/company/users/${id}`)
