import type { Trip } from '../types/api'

// "Valentin Imperial Maya → Aeropuerto T3": el lugar si lo hay, si no la zona.
// Sin el "lobby" del final, que en una etiqueta corta no aporta.
export function tripLabel(trip: Trip): string {
  const clean = (name: string | null | undefined) => name?.replace(/\s+lobby$/i, '').replace(/\s+salidas$/i, '') ?? null
  const origin = clean(trip.origin_place) ?? trip.origin_zone ?? 'Sin zona'
  const destination = clean(trip.destination_place) ?? trip.destination_zone ?? 'Sin zona'
  return `${origin} → ${destination}`
}