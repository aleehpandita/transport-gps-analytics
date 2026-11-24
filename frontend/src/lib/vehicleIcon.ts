import type { SignalStatus, VehicleIcon } from '../types/api'

// Dibujo de cada unidad vista desde arriba, apuntando al norte (el mapa la gira según el
// rumbo). Es texto SVG porque Leaflet dibuja los íconos como HTML suelto, sin React.

// Verde de siempre, para las unidades a las que el cliente no les ha elegido color
export const DEFAULT_VEHICLE_COLOR = '#3fb68b'

// Colores para elegir rápido. También se puede escoger cualquier otro.
export const COLOR_SWATCHES: { color: string; name: string }[] = [
  { color: '#3fb68b', name: 'Verde' },
  { color: '#2563eb', name: 'Azul' },
  { color: '#06b6d4', name: 'Cian' },
  { color: '#7c6cf2', name: 'Morado' },
  { color: '#ec4899', name: 'Rosa' },
  { color: '#e5566b', name: 'Rojo' },
  { color: '#f97316', name: 'Naranja' },
  { color: '#eab308', name: 'Amarillo' },
  { color: '#92400e', name: 'Café' },
  { color: '#1f2937', name: 'Negro' },
  { color: '#94a3b8', name: 'Gris' },
  { color: '#f8fafc', name: 'Blanco' },
]

export const ICON_LABEL: Record<VehicleIcon, string> = {
  van: 'Van / Camioneta',
  sedan: 'Sedán',
}

// Tamaño de cada dibujo (ancho × alto en px) a escala normal
export const ICON_SIZE: Record<VehicleIcon, [number, number]> = {
  van: [22, 42],
  sedan: [20, 36],
}

const GLASS = '#1c2433'
const OUTLINE = 'rgba(0,0,0,.55)'

export function vehicleSvg(icon: VehicleIcon, color: string | null, scale = 1): string {
  const fill = color ?? DEFAULT_VEHICLE_COLOR
  const [w, h] = ICON_SIZE[icon]

  const body =
    icon === 'van'
      ? `
      <rect x="1" y="5" width="2" height="4" rx="1" fill="${fill}" stroke="${OUTLINE}" stroke-width=".8"/>
      <rect x="19" y="5" width="2" height="4" rx="1" fill="${fill}" stroke="${OUTLINE}" stroke-width=".8"/>
      <rect x="2.5" y="1" width="17" height="40" rx="4" fill="${fill}" stroke="${OUTLINE}" stroke-width="1.2"/>
      <path d="M5 8.5 Q11 5.5 17 8.5 L16 13 L6 13 Z" fill="${GLASS}"/>
      <rect x="5" y="15" width="12" height="21" rx="2" fill="rgba(255,255,255,.22)"/>
      <path d="M7 17.5h8 M7 21.5h8 M7 25.5h8 M7 29.5h8 M7 33.5h8" stroke="rgba(0,0,0,.18)" stroke-width=".8"/>
      <rect x="5.5" y="37.5" width="11" height="2" rx="1" fill="${GLASS}"/>`
      : `
      <rect x="0.5" y="9" width="2" height="3.5" rx="1" fill="${fill}" stroke="${OUTLINE}" stroke-width=".8"/>
      <rect x="17.5" y="9" width="2" height="3.5" rx="1" fill="${fill}" stroke="${OUTLINE}" stroke-width=".8"/>
      <rect x="2" y="1" width="16" height="34" rx="6.5" fill="${fill}" stroke="${OUTLINE}" stroke-width="1.2"/>
      <path d="M5 11.5 Q10 9 15 11.5 L14 15.5 L6 15.5 Z" fill="${GLASS}"/>
      <rect x="5.5" y="16.5" width="9" height="8" rx="2" fill="rgba(255,255,255,.22)"/>
      <path d="M6 26 L14 26 L13.2 29.5 Q10 30.8 6.8 29.5 Z" fill="${GLASS}"/>
      <path d="M4.5 4 Q10 2 15.5 4" stroke="rgba(255,255,255,.35)" stroke-width="1" fill="none"/>`

  return `<svg width="${w * scale}" height="${h * scale}" viewBox="0 0 ${w} ${h}" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">${body}</svg>`
}

// Si el equipo está reportando (tablas de unidades del admin y del dueño)
export const VEHICLE_SIGNAL: Record<SignalStatus, { label: string; className: string }> = {
  ok: { label: 'Reportando', className: 'text-ok' },
  no_signal: { label: 'Sin reportar', className: 'text-risk' },
  never: { label: 'Nunca ha reportado', className: 'text-late' },
}
