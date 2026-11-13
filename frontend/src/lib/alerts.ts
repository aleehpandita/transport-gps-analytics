import type { AlertRule, AlertRuleInput, AlertType, Geofence, Vehicle } from '../types/api'

// Nombre, color y explicación de cada tipo de alerta. Rojo lo grave, ámbar lo que hay que
// revisar, azul lo informativo.
export const ALERT_TYPES: { type: AlertType; label: string; tone: string; help: string }[] = [
  {
    type: 'overspeed',
    label: 'Exceso de velocidad',
    tone: 'bg-late',
    help: 'Avisa si la unidad pasa del límite por varios segundos seguidos. Un rebase rápido no cuenta.',
  },
  {
    type: 'geofence_exit',
    label: 'Sale de geocerca',
    tone: 'bg-risk',
    help: 'Avisa cuando la unidad sale de una geocerca que dibujaste.',
  },
  {
    type: 'geofence_enter',
    label: 'Entra a geocerca',
    tone: 'bg-accent',
    help: 'Avisa cuando la unidad entra a una geocerca que dibujaste.',
  },
  {
    type: 'long_stop',
    label: 'Parada larga',
    tone: 'bg-risk',
    help: 'Avisa si la unidad se queda detenida en el mismo punto más del tiempo que elijas.',
  },
  {
    type: 'signal_lost',
    label: 'Sin señal',
    tone: 'bg-risk',
    help: 'Avisa si la unidad deja de reportar mientras va en movimiento o con el motor encendido. Estacionada es normal que se duerma.',
  },
  {
    type: 'after_hours',
    label: 'Movimiento fuera de horario',
    tone: 'bg-late',
    help: 'Avisa si la unidad se mueve en el horario que elijas abajo (por ejemplo, de noche).',
  },
]

export const alertTypeInfo = (type: AlertType) => ALERT_TYPES.find((t) => t.type === type) ?? ALERT_TYPES[0]

export const DAYS: { day: number; short: string; long: string }[] = [
  { day: 1, short: 'L', long: 'Lunes' },
  { day: 2, short: 'M', long: 'Martes' },
  { day: 3, short: 'M', long: 'Miércoles' },
  { day: 4, short: 'J', long: 'Jueves' },
  { day: 5, short: 'V', long: 'Viernes' },
  { day: 6, short: 'S', long: 'Sábado' },
  { day: 7, short: 'D', long: 'Domingo' },
]

export const REPEAT_OPTIONS: { minutes: number; label: string }[] = [
  { minutes: 0, label: 'Cada vez que pase' },
  { minutes: 15, label: 'Máximo uno cada 15 min' },
  { minutes: 30, label: 'Máximo uno cada 30 min' },
  { minutes: 60, label: 'Máximo uno cada hora' },
  { minutes: 180, label: 'Máximo uno cada 3 horas' },
  { minutes: 1440, label: 'Máximo uno al día' },
]

export const GEOFENCE_COLORS = ['#5b8def', '#3fb68b', '#e3a946', '#e5566b', '#7c6cf2', '#4fc1d6']

const ALL_DAYS = [1, 2, 3, 4, 5, 6, 7]

// Lo que trae el formulario al elegir un tipo
export function defaultRule(type: AlertType): AlertRuleInput {
  const base: AlertRuleInput = {
    name: alertTypeInfo(type).label,
    type,
    settings: {},
    vehicle_ids: null,
    days: ALL_DAYS,
    time_from: null,
    time_to: null,
    repeat_minutes: 30,
    emails: [],
    active: true,
  }

  switch (type) {
    case 'overspeed':
      return { ...base, settings: { speed_kmh: 100, seconds: 30 } }
    case 'long_stop':
      return { ...base, settings: { minutes: 30, except_in_geofences: true } }
    case 'signal_lost':
      return { ...base, settings: { minutes: 15 } }
    case 'after_hours':
      return { ...base, time_from: '22:00', time_to: '05:00' }
    default:
      return base
  }
}

function daysLabel(days: number[]): string {
  const sorted = [...days].sort()
  if (sorted.length === 7) return 'Todos los días'
  if (sorted.join() === '1,2,3,4,5') return 'Lunes a viernes'
  if (sorted.join() === '6,7') return 'Fin de semana'
  return sorted.map((d) => DAYS[d - 1].long.slice(0, 3)).join(', ')
}

// Resumen de una línea: "Más de 100 km/h por 30 s · Todas las unidades · Todos los días..."
export function ruleSummary(rule: AlertRule, vehicles: Vehicle[], geofences: Geofence[]): string {
  const s = rule.settings
  const fence = geofences.find((g) => g.id === s.geofence_id)?.name ?? 'geocerca borrada'

  const what = {
    overspeed: `Más de ${s.speed_kmh} km/h por ${s.seconds} s`,
    geofence_exit: `Sale de «${fence}»`,
    geofence_enter: `Entra a «${fence}»`,
    long_stop: `Detenida ${s.minutes} min${s.except_in_geofences ? ', fuera de tus geocercas' : ''}`,
    signal_lost: `${s.minutes} min sin reportar`,
    after_hours: 'Se mueve',
  }[rule.type]

  const units = rule.vehicle_ids
    ? rule.vehicle_ids.map((id) => vehicles.find((v) => v.id === id)?.name ?? `#${id}`).join(', ')
    : 'Todas las unidades'
  const hours = rule.time_from && rule.time_to ? `de ${rule.time_from} a ${rule.time_to}` : 'todo el día'
  const mail = rule.emails.length === 0 ? 'solo en la bandeja' : rule.emails.length === 1 ? rule.emails[0] : `${rule.emails.length} correos`

  return [what, units, `${daysLabel(rule.days)} ${hours}`, mail].join(' · ')
}
