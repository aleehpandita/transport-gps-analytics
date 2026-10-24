<?php

/**
 * Umbrales de los eventos y alertas. Todos se pueden cambiar desde .env sin tocar código.
 * Más adelante cada empresa tendrá los suyos; por ahora valen para toda la flota.
 */
return [

    // Exceso de velocidad: arriba de este límite, sostenido al menos estos segundos.
    // Un solo pico (un rebase rápido) no cuenta.
    'overspeed_kmh' => (int) env('ALERT_OVERSPEED_KMH', 120),
    'overspeed_seconds' => (int) env('ALERT_OVERSPEED_SECONDS', 30),

    // Parada larga: detenida estos minutos FUERA de un lugar del catálogo.
    // Esperar en un lobby, en la terminal o en la base es parte del servicio y no cuenta.
    'long_stop_minutes' => (int) env('ALERT_LONG_STOP_MINUTES', 20),
    // Radio en metros en el que se considera que sigue en el mismo punto
    'long_stop_radius_m' => (int) env('ALERT_LONG_STOP_RADIUS_M', 60),
    // Cerca del aeropuerto esperar vuelos es lo normal: a menos de estos metros de una
    // terminal del catálogo no hay parada larga. Medido del 19 al 23 oct: las esperas
    // quedan a 120 m y 1 km de las terminales.
    'long_stop_airport_m' => (int) env('ALERT_LONG_STOP_AIRPORT_M', 1500),

    // Sin señal: minutos sin reportar estando en movimiento (en movimiento manda cada 10 s).
    'signal_lost_moving_minutes' => (int) env('ALERT_SIGNAL_LOST_MOVING_MINUTES', 10),
    // Detenida: 0 = no avisar. Medido del 19 al 23 oct: estacionado, el FMC920 se duerme y
    // deja de reportar hasta que la van se vuelve a mover (de 6 a 15 h cada noche).
    'signal_lost_stopped_minutes' => (int) env('ALERT_SIGNAL_LOST_STOPPED_MINUTES', 0),

    // Entrada y salida de zona: posiciones seguidas en la zona nueva para confirmarla.
    // Evita que una unidad que va por la frontera entre y salga diez veces.
    'zone_confirm_positions' => (int) env('ALERT_ZONE_CONFIRM_POSITIONS', 2),

    // Llegada a un lugar (lobby, terminal): dentro de su radio y a menos de esta velocidad.
    'place_slow_kmh' => 10,
    // Salida del lugar: hasta que se aleja este múltiplo del radio. Así no "sale" y
    // "vuelve a llegar" por el ruido del GPS en la orilla.
    'place_leave_factor' => 1.5,

    // Debajo de esta velocidad la unidad está detenida (igual que el Trip Builder)
    'idle_speed_kmh' => 3,
];