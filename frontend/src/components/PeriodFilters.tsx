import { PRESETS, type Period } from '../hooks/usePeriod'
import type { Vehicle } from '../types/api'

// Barra de filtros compartida por Reportes y Eventos: unidad y periodo.

interface PeriodFiltersProps {
  vehicles: Vehicle[]
  vehicleId: number | null
  onVehicleChange: (id: number | null) => void
  period: Period
}

export function PeriodFilters({ vehicles, vehicleId, onVehicleChange, period }: PeriodFiltersProps) {
  const { preset, range, today } = period

  return (
    <div className="flex flex-wrap items-end gap-x-6 gap-y-3 border-b border-line px-4 py-4 text-sm lg:px-6">
      <label className="flex flex-col gap-1">
        <span className="text-ink-muted">Unidad</span>
        <select
          value={vehicleId ?? ''}
          onChange={(e) => onVehicleChange(e.target.value === '' ? null : Number(e.target.value))}
          className="rounded-md border border-line bg-surface-raised px-3 py-1.5"
        >
          {vehicles.length > 1 && <option value="">Todas las unidades</option>}
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.name}
            </option>
          ))}
        </select>
      </label>

      <div className="flex flex-col gap-1">
        <span className="text-ink-muted">Periodo</span>
        <div className="flex flex-wrap gap-1" role="group" aria-label="Periodo">
          {PRESETS.map((p) => (
            <button
              key={p.id}
              type="button"
              onClick={() => period.choosePreset(p.id)}
              aria-pressed={preset === p.id}
              className={`rounded-md px-3 py-1.5 ${preset === p.id ? 'bg-surface-raised font-medium' : 'text-ink-muted hover:bg-surface-raised'}`}
            >
              {p.label}
            </button>
          ))}
        </div>
      </div>

      {preset === 'custom' && (
        <div className="flex items-end gap-2">
          <label className="flex flex-col gap-1">
            <span className="text-ink-muted">Desde</span>
            <input
              type="date"
              value={range.from}
              max={range.to}
              onChange={(e) => period.setFrom(e.target.value)}
              className="rounded-md border border-line bg-surface-raised px-3 py-1.5"
            />
          </label>
          <label className="flex flex-col gap-1">
            <span className="text-ink-muted">Hasta</span>
            <input
              type="date"
              value={range.to}
              min={range.from}
              max={today}
              onChange={(e) => period.setTo(e.target.value)}
              className="rounded-md border border-line bg-surface-raised px-3 py-1.5"
            />
          </label>
        </div>
      )}
    </div>
  )
}