import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// En desarrollo, el panel (5173) y Laravel (8000) se ven como un solo sitio: Vite reenvía
// estas rutas a Laravel. Así se evita CORS y la cookie de sesión queda en localhost:5173.
const laravel = {
  target: 'http://127.0.0.1:8000',
  changeOrigin: true,
}

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    proxy: {
      '/api': laravel,
      // Sanctum: entrega la cookie XSRF-TOKEN antes del login
      '/sanctum': laravel,
    },
  },
})