import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { RoutePoint } from '../types/api'

// Velocidades de reproducción: segundos del día por segundo de pantalla
export const PLAYBACK_SPEEDS = [10, 60, 300, 1200] as const
export type PlaybackSpeed = (typeof PLAYBACK_SPEEDS)[number]

// Una espera más larga que esto se brinca cuando "Saltar paradas" está prendido
const SKIP_GAP_SECONDS = 180

export interface PlaybackFrame {
  lat: number
  lng: number
  speed: number
  course: number
}

// Índice de la última posición con timestamp <= ts (búsqueda binaria)
export function indexAt(points: RoutePoint[], ts: number): number {
  let lo = 0
  let hi = points.length - 1
  if (hi < 0 || ts <= points[0][0]) return 0
  if (ts >= points[hi][0]) return hi
  while (lo < hi) {
    const mid = (lo + hi + 1) >> 1
    if (points[mid][0] <= ts) lo = mid
    else hi = mid - 1
  }
  return lo
}

// Posición interpolada en el segundo ts, entre las dos posiciones que lo rodean
export function frameAt(points: RoutePoint[], ts: number): PlaybackFrame | null {
  if (points.length === 0) return null
  const i = indexAt(points, ts)
  const a = points[i]
  const b = points[Math.min(i + 1, points.length - 1)]
  const span = b[0] - a[0]
  const k = span > 0 ? Math.min(1, Math.max(0, (ts - a[0]) / span)) : 0
  return {
    lat: a[1] + (b[1] - a[1]) * k,
    lng: a[2] + (b[2] - a[2]) * k,
    speed: a[3],
    course: a[4],
  }
}

/**
 * Reloj de la repetición. El tiempo avanza con requestAnimationFrame a la velocidad elegida;
 * con skipStops, al caer en una espera larga brinca a la siguiente posición.
 */
export function usePlayback(points: RoutePoint[]) {
  const first = points[0]?.[0] ?? 0
  const last = points[points.length - 1]?.[0] ?? 0

  const [time, setTime] = useState(first)
  const [playing, setPlaying] = useState(false)
  const [speed, setSpeed] = useState<PlaybackSpeed>(60)
  const [skipStops, setSkipStops] = useState(true)

  // El ciclo de animación lee el tiempo de aquí, sin tener que reiniciarse en cada cuadro
  const timeRef = useRef(time)
  useEffect(() => {
    timeRef.current = time
  }, [time])

  // Día nuevo: al principio y en pausa
  useEffect(() => {
    setTime(first)
    setPlaying(false)
  }, [points, first])

  useEffect(() => {
    if (!playing) return

    let frame = 0
    let previous = performance.now()

    const step = (now: number) => {
      const elapsed = (now - previous) / 1000
      previous = now

      let next = timeRef.current + elapsed * speed

      if (skipStops) {
        const i = indexAt(points, timeRef.current)
        const following = points[i + 1]
        if (following && following[0] - points[i][0] > SKIP_GAP_SECONDS && next < following[0]) {
          next = following[0]
        }
      }

      if (next >= last) {
        timeRef.current = last
        setTime(last)
        setPlaying(false)
        return
      }

      timeRef.current = next
      setTime(next)
      frame = requestAnimationFrame(step)
    }

    frame = requestAnimationFrame(step)
    return () => cancelAnimationFrame(frame)
  }, [playing, speed, skipStops, points, last])

  const seek = useCallback((ts: number) => setTime(Math.min(last, Math.max(first, ts))), [first, last])

  const toggle = useCallback(() => {
    // Si ya terminó, play vuelve a empezar
    if (!playing && timeRef.current >= last) setTime(first)
    setPlaying((p) => !p)
  }, [playing, first, last])

  const frame = useMemo(() => frameAt(points, time), [points, time])

  return { time, first, last, frame, playing, toggle, setPlaying, seek, speed, setSpeed, skipStops, setSkipStops }
}

export type Playback = ReturnType<typeof usePlayback>