import { useState, type FormEvent } from 'react'
import { ApiError, login } from '../lib/apiClient'
import type { User } from '../types/api'

interface LoginScreenProps {
  onLoggedIn: (user: User) => void
}

export function LoginScreen({ onLoggedIn }: LoginScreenProps) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      onLoggedIn(await login(email.trim(), password))
    } catch (e) {
      // 422 trae el mensaje de Laravel ("Correo o contraseña incorrectos", "Demasiados intentos...")
      setError(
        e instanceof ApiError && e.status === 422
          ? e.message
          : 'No se pudo iniciar sesión. Revisa que Laravel esté corriendo en el puerto 8000.',
      )
      setPassword('')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center p-4">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-sm rounded-lg border border-line bg-surface p-6 shadow-lg"
        noValidate
      >
        <p className="font-mono text-[11px] tracking-[0.14em] text-ink-muted uppercase">Panel de flota</p>
        <h1 className="mt-1 mb-6 text-xl font-semibold">Iniciar sesión</h1>

        <label htmlFor="login-email" className="mb-1 block text-sm text-ink-muted">
          Correo
        </label>
        <input
          id="login-email"
          type="email"
          autoComplete="username"
          required
          autoFocus
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className="mb-4 w-full rounded-md border border-line bg-surface-raised px-3 py-2 outline-none focus:border-ok"
        />

        <label htmlFor="login-password" className="mb-1 block text-sm text-ink-muted">
          Contraseña
        </label>
        <input
          id="login-password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className="mb-4 w-full rounded-md border border-line bg-surface-raised px-3 py-2 outline-none focus:border-ok"
        />

        {error && (
          <p role="alert" className="mb-4 rounded-md border border-late/40 bg-late/10 px-3 py-2 text-sm text-late">
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={submitting || email.trim() === '' || password === ''}
          className="w-full rounded-md bg-ok px-4 py-2 font-medium text-black transition-opacity disabled:opacity-50"
        >
          {submitting ? 'Entrando…' : 'Entrar'}
        </button>
      </form>
    </div>
  )
}