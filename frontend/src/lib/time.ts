// Fechas y horas del historial, siempre en hora de Cancún (la base guarda UTC)

const CANCUN = 'America/Cancun'

const clockFormat = new Intl.DateTimeFormat('es-MX', {
  timeZone: CANCUN,
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

const clockSecondsFormat = new Intl.DateTimeFormat('es-MX', {
  timeZone: CANCUN,
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hourCycle: 'h23',
})

const dayLabelFormat = new Intl.DateTimeFormat('es-MX', {
  timeZone: 'UTC', // la fecha ya es de Cancún; se formatea tal cual
  weekday: 'short',
  day: 'numeric',
  month: 'short',
})

// Hoy en Cancún como YYYY-MM-DD ('en-CA' da justo ese formato)
export function cancunToday(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: CANCUN }).format(new Date())
}

// Suma días a una fecha YYYY-MM-DD sin pasar por la zona horaria del navegador
export function shiftDate(date: string, days: number): string {
  const d = new Date(`${date}T12:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

// "mar 21 oct"
export function formatDayLabel(date: string): string {
  return dayLabelFormat.format(new Date(`${date}T12:00:00Z`)).replace(/\./g, '').replace(',', '')
}

// Timestamp en segundos (o ISO) a "08:26"
export function formatClock(value: number | string): string {
  return clockFormat.format(typeof value === 'number' ? value * 1000 : new Date(value))
}

// Timestamp en segundos a "08:26:41"
export function formatClockSeconds(ts: number): string {
  return clockSecondsFormat.format(ts * 1000)
}

// Segundos a "25 min" o "5 h 12 min"
export function formatDuration(seconds: number): string {
  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return `${minutes} min`
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m === 0 ? `${h} h` : `${h} h ${m} min`
}

export function toTimestamp(iso: string): number {
  return Math.floor(new Date(iso).getTime() / 1000)
}

// Fecha de Cancún (YYYY-MM-DD) de un momento ISO
export function cancunDateOf(iso: string): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: CANCUN }).format(new Date(iso))
}

// Primer día del mes de una fecha YYYY-MM-DD
export function startOfMonth(date: string): string {
  return `${date.slice(0, 8)}01`
}