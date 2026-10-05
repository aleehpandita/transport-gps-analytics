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

export const formatTime = (value: Date | string) => timeFmt.format(new Date(value))

export const formatDateTime = (value: Date | string) => dateTimeFmt.format(new Date(value))

export const formatKm = (km: number) =>
  `${km.toLocaleString('es-MX', { maximumFractionDigits: 1 })} km`

export const formatSpeed = (kmh: number) => `${Math.round(kmh)} km/h`