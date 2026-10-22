import { useEffect, useState } from 'react'
import { Dashboard } from './components/Dashboard'
import { HistoryView } from './components/HistoryView'
import { LoginScreen } from './components/LoginScreen'
import { AUTH_EXPIRED_EVENT, fetchMe, logout } from './lib/apiClient'
import type { User } from './types/api'

type AuthState =
  | { status: 'checking' } // al abrir: preguntando a /api/me si ya hay sesión
  | { status: 'guest' }
  | { status: 'authenticated'; user: User }

// Rutas por hash, sin librería: #/ es el mapa en vivo y #/historial/2/2026-10-21 el historial.
// Con hash, Nginx no necesita configuración extra para que funcione recargar la página.
type Route = { page: 'live' } | { page: 'history'; vehicleId: number | null; date: string | null }

function parseHash(hash: string): Route {
  const match = hash.match(/^#\/historial(?:\/(\d+))?(?:\/(\d{4}-\d{2}-\d{2}))?/)
  if (!match) return { page: 'live' }
  return { page: 'history', vehicleId: match[1] ? Number(match[1]) : null, date: match[2] ?? null }
}

function useHashRoute(): Route {
  const [route, setRoute] = useState(() => parseHash(window.location.hash))

  useEffect(() => {
    const onChange = () => setRoute(parseHash(window.location.hash))
    window.addEventListener('hashchange', onChange)
    return () => window.removeEventListener('hashchange', onChange)
  }, [])

  return route
}

// Decide qué se muestra: el login, el mapa en vivo o el historial
export default function App() {
  const [auth, setAuth] = useState<AuthState>({ status: 'checking' })
  const route = useHashRoute()

  // Al abrir: si la cookie de sesión sigue viva, entra directo sin pedir contraseña
  useEffect(() => {
    const controller = new AbortController()

    fetchMe(controller.signal)
      .then((user) => setAuth({ status: 'authenticated', user }))
      .catch(() => {
        if (!controller.signal.aborted) setAuth({ status: 'guest' })
      })

    return () => controller.abort()
  }, [])

  // Si en cualquier momento la API responde 401 o 419, la sesión expiró: de vuelta al login
  useEffect(() => {
    const onExpired = () => setAuth({ status: 'guest' })
    window.addEventListener(AUTH_EXPIRED_EVENT, onExpired)
    return () => window.removeEventListener(AUTH_EXPIRED_EVENT, onExpired)
  }, [])

  async function handleLogout() {
    try {
      await logout()
    } catch {
      // Aunque falle (sesión ya expirada, por ejemplo), en el panel se sale igual
    }
    setAuth({ status: 'guest' })
  }

  if (auth.status === 'checking') {
    return <p className="p-6 text-sm text-ink-muted">Cargando…</p>
  }

  if (auth.status === 'guest') {
    return <LoginScreen onLoggedIn={(user) => setAuth({ status: 'authenticated', user })} />
  }

  if (route.page === 'history') {
    return (
      <HistoryView
        // Al cambiar de unidad o día desde un link, el historial arranca de cero
        key={`${route.vehicleId ?? ''}-${route.date ?? ''}`}
        user={auth.user}
        initialVehicleId={route.vehicleId}
        initialDate={route.date}
        onLogout={handleLogout}
      />
    )
  }

  return <Dashboard user={auth.user} onLogout={handleLogout} />
}