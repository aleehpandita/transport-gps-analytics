// Tema del panel: claro u oscuro. Se guarda por usuario en el servidor (PATCH /me/preferences)
// y también en este navegador, para pintar el tema correcto desde el primer instante
// (antes de que responda /me, y en la pantalla de login).

export type Theme = 'light' | 'dark'

const STORAGE_KEY = 'ekmira:theme'
// Los usuarios nuevos empiezan en claro: se lee mejor en el celular, al sol
export const DEFAULT_THEME: Theme = 'light'

export function readStoredTheme(): Theme {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    return saved === 'dark' || saved === 'light' ? saved : DEFAULT_THEME
  } catch {
    return DEFAULT_THEME
  }
}

export function applyTheme(theme: Theme): void {
  document.documentElement.dataset.theme = theme
  try {
    localStorage.setItem(STORAGE_KEY, theme)
  } catch {
    // Sin almacenamiento (navegación privada): solo no se recuerda en este navegador
  }
}

export function currentTheme(): Theme {
  return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'
}
