import type { ReactNode } from 'react'

const CIRCLED = ['①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨']

interface PanelProps {
  index: number
  title: string
  aside?: ReactNode
  children: ReactNode
  className?: string
}

export function Panel({ index, title, aside, children, className = '' }: PanelProps) {
  return (
    <section className={`flex min-h-0 flex-col rounded-lg border border-line bg-surface ${className}`}>
      <header className="flex items-baseline justify-between gap-4 border-b border-line px-5 py-3">
        <h2 className="font-mono text-xs tracking-[0.14em] text-ink-muted">
          <span className="mr-2 text-accent">{CIRCLED[index - 1]}</span>
          {title}
        </h2>
        {aside && <div className="font-mono text-xs text-ink-muted">{aside}</div>}
      </header>
      <div className="min-h-0 flex-1">{children}</div>
    </section>
  )
}