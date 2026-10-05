export function MapPlaceholder() {
  return (
    <div className="flex h-full min-h-[320px] items-center justify-center bg-[radial-gradient(circle_at_center,var(--color-surface-raised),var(--color-surface))] p-6">
      <p className="max-w-xs text-center text-sm text-ink-muted">
        Aquí va el mapa con Leaflet. Por ahora la flota se ve en la tabla de abajo.
      </p>
    </div>
  )
}