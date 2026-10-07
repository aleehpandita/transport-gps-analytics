import type { Vehicle } from '../types/api'

export type VehicleStatus = 'moving' | 'idling' | 'off' | 'stale' | 'unknown'

// Umbrales medidos en el piloto (docs/piloto-fmc920.md, sección 4.1).
// En movimiento, el hueco máximo entre posiciones fue de 318 s; se suma margen
// por el Send Period de 120 s y por el intervalo del sync.
const STALE_MOVING_MINUTES = 8
// Detenida o apagada, el FMC920 reporta una vez por hora (On Stop, Min Period 3600 s).
const STALE_STOPPED_MINUTES = 65

export function vehicleStatus(vehicle: Vehicle, now: Date = new Date()): VehicleStatus {
  const p = vehicle.latest_position
  if (!p) return 'unknown'

  // Estado según la última posición, antes de considerar su antigüedad
  const lastKnown: VehicleStatus =
    p.ignition === false ? 'off' : p.speed > 0 ? 'moving' : 'idling'

  // El tiempo aceptable sin reportar depende de lo que estaba haciendo la unidad
  const limitMinutes = lastKnown === 'moving' ? STALE_MOVING_MINUTES : STALE_STOPPED_MINUTES
  const ageMinutes = Math.abs(now.getTime() - new Date(p.device_time).getTime()) / 60_000

  return ageMinutes > limitMinutes ? 'stale' : lastKnown
}

export const STATUS_LABEL: Record<VehicleStatus, string> = {
  moving: 'En ruta',
  idling: 'Detenido, motor encendido',
  off: 'Apagado',
  stale: 'Sin señal reciente',
  unknown: 'Sin posición',
}

export const STATUS_DOT: Record<VehicleStatus, string> = {
  moving: 'bg-ok',
  idling: 'bg-risk',
  off: 'bg-ink-muted',
  stale: 'bg-late',
  unknown: 'bg-line',
}

// Orden para la tabla: lo que requiere atención primero
export const STATUS_ORDER: Record<VehicleStatus, number> = {
  moving: 0,
  idling: 1,
  stale: 2,
  off: 3,
  unknown: 4,
}