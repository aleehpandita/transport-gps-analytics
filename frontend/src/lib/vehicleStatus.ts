import type { Vehicle } from '../types/api'

export type VehicleStatus = 'moving' | 'stopped' | 'parked' | 'stale' | 'unknown'

// Mismo umbral que TripBuilderService y VehicleResource: el GPS reporta 1 o 2 km/h
// de ruido estando parada, así que menos de 3 km/h cuenta como detenida.
const IDLE_SPEED_KMH = 3

// Igual que la regla de permanencia del Trip Builder: detenida 3 minutos o más ya es
// una parada real (bajada de pasajeros, espera en el aeropuerto), no un semáforo.
const PARKED_AFTER_MINUTES = 3

// Umbrales medidos en el piloto (docs/piloto-fmc920.md, sección 4.1).
// En movimiento, el hueco máximo entre posiciones fue de 318 s; se suma margen
// por el Send Period de 120 s y por el intervalo del sync.
const STALE_MOVING_MINUTES = 8
// Detenida o apagada, el FMC920 reporta una vez por hora (On Stop, Min Period 3600 s).
// Por eso una unidad estacionada puede verse "desconectada" en Traccar y seguir bien aquí.
const STALE_STOPPED_MINUTES = 65

// El estado no usa la ignición: en Kevs1 la señal parpadeaba por el umbral de voltaje
// (docs/piloto-fmc920.md). Se basa solo en velocidad y tiempo.
export function vehicleStatus(vehicle: Vehicle, now: Date = new Date()): VehicleStatus {
  const p = vehicle.latest_position
  if (!p) return 'unknown'

  const isMoving = p.speed >= IDLE_SPEED_KMH

  // El tiempo aceptable sin reportar depende de lo que estaba haciendo la unidad
  const limitMinutes = isMoving ? STALE_MOVING_MINUTES : STALE_STOPPED_MINUTES
  if (minutesSince(p.device_time, now) > limitMinutes) return 'stale'

  if (isMoving) return 'moving'

  const since = vehicle.stopped_since ?? p.device_time
  return minutesSince(since, now) >= PARKED_AFTER_MINUTES ? 'parked' : 'stopped'
}

// Texto del estado con su hora: "Estacionada desde 17:35", "Sin señal desde 14 oct 19:54"
export function statusLabel(vehicle: Vehicle, status: VehicleStatus, now: Date = new Date()): string {
  if (status === 'parked' && vehicle.stopped_since) {
    return `${STATUS_LABEL.parked} desde ${formatSince(vehicle.stopped_since, now)}`
  }

  if (status === 'stale' && vehicle.latest_position) {
    return `Sin señal desde ${formatSince(vehicle.latest_position.device_time, now)}`
  }

  return STATUS_LABEL[status]
}

export const STATUS_LABEL: Record<VehicleStatus, string> = {
  moving: 'En ruta',
  stopped: 'En ruta, detenida',
  parked: 'Estacionada',
  stale: 'Sin señal reciente',
  unknown: 'Sin posición',
}

export const STATUS_DOT: Record<VehicleStatus, string> = {
  moving: 'bg-ok',
  stopped: 'bg-ok',
  parked: 'bg-risk',
  stale: 'bg-late',
  unknown: 'bg-line',
}

// Orden para la tabla: primero las que van en ruta, luego lo que requiere atención
export const STATUS_ORDER: Record<VehicleStatus, number> = {
  moving: 0,
  stopped: 1,
  stale: 2,
  parked: 3,
  unknown: 4,
}

function minutesSince(iso: string, now: Date): number {
  return Math.abs(now.getTime() - new Date(iso).getTime()) / 60_000
}

const CANCUN = 'America/Cancun'

const timeFormat = new Intl.DateTimeFormat('es-MX', {
  timeZone: CANCUN,
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

const dayFormat = new Intl.DateTimeFormat('es-MX', { timeZone: CANCUN, day: 'numeric', month: 'short' })

// Solo la hora si fue hoy (en Cancún); si fue otro día, también el día: "14 oct 19:54"
function formatSince(iso: string, now: Date): string {
  const date = new Date(iso)
  const time = timeFormat.format(date)
  const sameDay = dayFormat.format(date) === dayFormat.format(now)

  return sameDay ? time : `${dayFormat.format(date).replace('.', '')} ${time}`
}