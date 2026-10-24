import { useState } from 'react'
import { cancunToday, formatDayLabel, shiftDate, startOfMonth } from '../lib/time'

// Periodo de fechas (días de Cancún) para reportes y eventos: atajos o fechas a mano.

export type Preset = 'today' | 'yesterday' | 'week' | 'month' | 'custom'

export const PRESETS: { id: Preset; label: string }[] = [
  { id: 'today', label: 'Hoy' },
  { id: 'yesterday', label: 'Ayer' },
  { id: 'week', label: 'Últimos 7 días' },
  { id: 'month', label: 'Este mes' },
  { id: 'custom', label: 'Otras fechas' },
]

export interface DateRange {
  from: string
  to: string
}

function presetRange(preset: Exclude<Preset, 'custom'>, today: string): DateRange {
  switch (preset) {
    case 'today':
      return { from: today, to: today }
    case 'yesterday':
      return { from: shiftDate(today, -1), to: shiftDate(today, -1) }
    case 'week':
      return { from: shiftDate(today, -6), to: today }
    case 'month':
      return { from: startOfMonth(today), to: today }
  }
}

export interface Period {
  today: string
  preset: Preset
  range: DateRange
  label: string // "jue 1 de oct al vie 23 de oct"
  choosePreset: (preset: Preset) => void
  setFrom: (date: string) => void
  setTo: (date: string) => void
}

export function usePeriod(initial: Exclude<Preset, 'custom'>): Period {
  const today = cancunToday()
  const [preset, setPreset] = useState<Preset>(initial)
  const [range, setRange] = useState(() => presetRange(initial, today))

  return {
    today,
    preset,
    range,
    label: range.from === range.to ? formatDayLabel(range.from) : `${formatDayLabel(range.from)} al ${formatDayLabel(range.to)}`,
    choosePreset(next) {
      setPreset(next)
      if (next !== 'custom') setRange(presetRange(next, today))
    },
    setFrom: (from) => from && setRange((r) => ({ ...r, from })),
    setTo: (to) => to && setRange((r) => ({ ...r, to })),
  }
}