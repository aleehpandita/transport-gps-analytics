import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import 'leaflet/dist/leaflet.css'
import './index.css'
import App from './App'
import { applyTheme, readStoredTheme } from './lib/theme'

// El tema guardado en este navegador, antes de pintar nada (evita el destello del otro tema)
applyTheme(readStoredTheme())

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)