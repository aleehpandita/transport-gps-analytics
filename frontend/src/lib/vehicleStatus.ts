import type { Vehicle } from '../types/api'

export type VehicleStatus = 'moving' | 'idling' | 'off' | 'stale' | 'unknown'

// Si la última posición tiene más de esto, la unidad se considera sin señal
const STALE_MINUTES = 10

export function vehicleStatus(vehicle: Vehicle, now: Date = new Date()): VehicleStatus {
  const p = vehicle.latest_position
  if (!p) return 'unknown'

  const ageMinutes = Math.abs(now.getTime() - new Date(p.device_time).getTime()) / 60_000
  if (ageMinutes > STALE_MINUTES) return 'stale'
  if (p.ignition === false) return 'off'
  if (p.speed > 0) return 'moving'
  // Motor encendido y velocidad 0: parada intermedia o esperando cliente
  return 'idling'
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