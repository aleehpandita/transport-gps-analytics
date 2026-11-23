import { useState, type FormEvent, type ReactNode } from 'react'
import { COLOR_SWATCHES, DEFAULT_VEHICLE_COLOR, ICON_LABEL, vehicleSvg } from '../lib/vehicleIcon'
import type { VehicleEditInput } from '../lib/apiClient'
import type { AdminVehicle, VehicleIcon } from '../types/api'

// Una unidad en la tabla de Clientes (admin) o de Mi empresa (dueño). "Editar" abre en la
// misma fila nombre, placas, tipo de ícono y color en el mapa.

const input = 'rounded-md border border-line bg-surface-raised px-3 py-1.5'
const button = 'rounded-md border border-line px-2.5 py-1 text-ink-muted hover:bg-surface-raised disabled:opacity-60'
const primary = 'rounded-md border border-ok/50 bg-ok/15 px-3 py-1.5 font-medium text-ok hover:bg-ok/25 disabled:opacity-60'

const ICONS: VehicleIcon[] = ['van', 'sedan']

// El carrito como lo verá el cliente en el mapa
export function VehiclePreview({ icon, color, scale = 0.7, rotate = 0 }: { icon: VehicleIcon; color: string | null; scale?: number; rotate?: number }) {
  return (
    <span
      className="inline-flex shrink-0 items-center justify-center"
      style={{ transform: rotate ? `rotate(${rotate}deg)` : undefined }}
      dangerouslySetInnerHTML={{ __html: vehicleSvg(icon, color, scale) }}
    />
  )
}

interface VehicleEditRowProps {
  vehicle: AdminVehicle
  busy: boolean
  columns: number // columnas de la tabla, para que la fila de edición ocupe todo el ancho
  onSave: (input: VehicleEditInput) => Promise<boolean>
  children: ReactNode // las celdas de la fila normal, después del nombre
  actions?: ReactNode // botones extra junto a "Editar" (Quitar, en el panel del admin)
}

export function VehicleEditRow({ vehicle: v, busy, columns, onSave, children, actions }: VehicleEditRowProps) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(v.name)
  const [plate, setPlate] = useState(v.plate ?? '')
  const [icon, setIcon] = useState<VehicleIcon>(v.icon)
  const [color, setColor] = useState<string | null>(v.color)

  function startEdit() {
    setName(v.name)
    setPlate(v.plate ?? '')
    setIcon(v.icon)
    setColor(v.color)
    setEditing(true)
  }

  async function save(e: FormEvent) {
    e.preventDefault()
    const ok = await onSave({ name: name.trim(), plate: plate.trim().toUpperCase() || null, icon, color })
    if (ok) setEditing(false)
  }

  if (editing) {
    const current = (color ?? DEFAULT_VEHICLE_COLOR).toLowerCase()

    return (
      <tr>
        <td colSpan={columns} className="px-3 py-3">
          <form onSubmit={save} className="flex flex-col gap-4">
            <div className="flex flex-wrap items-end gap-3">
              <label className="flex flex-col gap-1">
                <span className="text-ink-muted">Nombre</span>
                <input required maxLength={80} value={name} onChange={(e) => setName(e.target.value)} className={input} />
              </label>
              <label className="flex flex-col gap-1">
                <span className="text-ink-muted">Placas</span>
                <input
                  maxLength={20}
                  value={plate}
                  onChange={(e) => setPlate(e.target.value.toUpperCase())}
                  placeholder="ABC123A"
                  className={`${input} uppercase`}
                />
              </label>
              {v.imei && <span className="pb-1.5 font-mono text-xs text-ink-muted">IMEI {v.imei}</span>}
            </div>

            <div className="flex flex-wrap items-start gap-6">
              <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 text-ink-muted">Tipo</legend>
                <div className="flex gap-2">
                  {ICONS.map((option) => (
                    <button
                      key={option}
                      type="button"
                      aria-pressed={icon === option}
                      onClick={() => setIcon(option)}
                      className={`flex flex-col items-center gap-1.5 rounded-md border px-3 py-2 ${
                        icon === option ? 'border-ok bg-ok/15 font-medium' : 'border-line text-ink-muted hover:bg-surface-raised'
                      }`}
                    >
                      <VehiclePreview icon={option} color={color} scale={0.9} />
                      <span className="text-xs">{ICON_LABEL[option]}</span>
                    </button>
                  ))}
                </div>
              </fieldset>

              <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 text-ink-muted">Color en el mapa</legend>
                <div className="grid grid-cols-6 gap-2">
                  {COLOR_SWATCHES.map((s) => (
                    <button
                      key={s.color}
                      type="button"
                      title={s.name}
                      aria-label={s.name}
                      aria-pressed={current === s.color}
                      onClick={() => setColor(s.color)}
                      className={`h-7 w-7 rounded-full border ${
                        current === s.color ? 'ring-2 ring-ink ring-offset-2 ring-offset-surface' : 'border-line'
                      }`}
                      style={{ background: s.color }}
                    />
                  ))}
                </div>
                <label className="flex items-center gap-2 text-ink-muted">
                  Otro:
                  <input
                    type="color"
                    value={current}
                    onChange={(e) => setColor(e.target.value)}
                    className="h-7 w-10 cursor-pointer rounded border border-line bg-transparent"
                  />
                </label>
              </fieldset>
            </div>

            <div className="flex gap-2">
              <button type="submit" disabled={busy} className={primary}>
                Guardar
              </button>
              <button type="button" disabled={busy} className={button} onClick={() => setEditing(false)}>
                Cancelar
              </button>
            </div>
          </form>
        </td>
      </tr>
    )
  }

  return (
    <tr>
      <td className="px-3 py-2">
        <span className="inline-flex items-center gap-2">
          <VehiclePreview icon={v.icon} color={v.color} scale={0.55} rotate={90} />
          {v.name}
          {!v.active && <span className="text-xs text-ink-muted">(inactiva)</span>}
        </span>
      </td>
      {children}
      <td className="whitespace-nowrap px-3 py-2 text-right">
        <button type="button" disabled={busy} className={`${button} mr-2`} onClick={startEdit}>
          Editar
        </button>
        {actions}
      </td>
    </tr>
  )
}
