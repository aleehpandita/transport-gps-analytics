import type {
  Collection,
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
  await ensureCsrfCookie()

  const res = await fetch(`/api${path}`, {
    method: 'POST',
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
    throw new ApiError(res.status, `POST /api${path} respondió ${res.status}`)
  }

  return res.json() as Promise<T>
}

// Sesión
export const login = (email: string, password: string) =>
  postJson<{ data: User }>('/login', { email, password }).then((r) => r.data)

export const logout = () => postJson<{ message: string }>('/logout')

export const fetchMe = (signal?: AbortSignal) => getJson<{ data: User }>('/me', signal).then((r) => r.data)

// Fetchers estables (nivel de módulo) para usarlos con usePolling sin re-crear el efecto
export const fetchVehicles = (signal: AbortSignal) =>
  getJson<Collection<Vehicle>>('/vehicles', signal).then((r) => r.data)

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

  return getJson<Paginated<ScheduledService>>(`/scheduled-services?${params}`, signal).then(
    (r) => r.data,
  )
}

// Zonas activas con su geometría, para dibujarlas en el mapa
export const fetchZones = (signal: AbortSignal) =>
  getJson<Collection<MapZone>>('/zones', signal).then((r) => r.data)

// Recorrido completo de una unidad en un día de Cancún (YYYY-MM-DD), para el historial
export const fetchVehicleRoute = (vehicleId: number, date: string, signal?: AbortSignal) =>
  getJson<{ data: RouteDay }>(`/vehicles/${vehicleId}/route?date=${encodeURIComponent(date)}`, signal).then(
    (r) => r.data,
  )

// Viajes de hoy de las unidades del usuario. El backend corta el día en hora Cancún.
export const fetchTodayTrips = (signal: AbortSignal) =>
  getJson<Collection<Trip>>('/trips', signal).then((r) => r.data)