import { useEffect, useState } from 'react'
import { Dashboard } from './components/Dashboard'
import { LoginScreen } from './components/LoginScreen'
import { AUTH_EXPIRED_EVENT, fetchMe, logout } from './lib/apiClient'
import type { User } from './types/api'

type AuthState =
  | { status: 'checking' } // al abrir: preguntando a /api/me si ya hay sesión
  | { status: 'guest' }
  | { status: 'authenticated'; user: User }

// Decide qué se muestra: el login o el panel. El panel completo vive en Dashboard.tsx.
export default function App() {
  const [auth, setAuth] = useState<AuthState>({ status: 'checking' })

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

  return <Dashboard user={auth.user} onLogout={handleLogout} />
}