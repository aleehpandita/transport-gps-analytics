# Panel (React + TypeScript + Vite)

Panel de operación de la plataforma. Se compila a archivos estáticos que sirve Nginx con HTTPS.

## Vistas

- Mapa en vivo de la flota y estado de cada unidad (Leaflet)
- Viajes e historial por unidad, con su recorrido
- Eventos y bandeja de alertas
- Reglas de alertas y geocercas dibujadas en el mapa
- Reportes de distancia, entradas y salidas, y alertas, con descarga en Excel
- Vista adaptada a celular

## Arranque local

```bash
npm install
npm run dev     # desarrollo
npm run build   # genera dist/ para producción
```

El panel llama al backend en la ruta relativa `/api`, así que en producción se sirve desde el mismo dominio que Laravel.
