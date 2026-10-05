// Todo se muestra en hora Cancún; la API entrega UTC
const TZ = 'America/Cancun'

const timeFmt = new Intl.DateTimeFormat('es-MX', {
  timeZone: TZ,
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

const dateTimeFmt = new Intl.DateTimeFormat('es-MX', {
  timeZone: TZ,
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

const shortDateTimeFmt = new Intl.DateTimeFormat('es-MX', {
  timeZone: TZ,
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

// 'en-CA' produce el formato AAAA-MM-DD, útil como llave para comparar días
const dayKeyFmt = new Intl.DateTimeFormat('en-CA', {
  timeZone: TZ,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

const dayKey = (date: Date) => dayKeyFmt.format(date)

export const formatTime = (value: Date | string) => timeFmt.format(new Date(value))

export const formatDateTime = (value: Date | string) => dateTimeFmt.format(new Date(value))

/**
 * Hora sola si es del mismo día en Cancún; fecha y hora si es de otro día.
 * Pensado para "última señal", donde una hora sin fecha puede engañar.
 */
export function formatLastSeen(value: Date | string, now: Date = new Date()): string {
  const date = new Date(value)
  return dayKey(date) === dayKey(now) ? timeFmt.format(date) : shortDateTimeFmt.format(date)
}

export const formatKm = (km: number) =>
  `${km.toLocaleString('es-MX', { maximumFractionDigits: 1 })} km`

export const formatSpeed = (kmh: number) => `${Math.round(kmh)} km/h`