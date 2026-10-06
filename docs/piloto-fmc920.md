# Piloto Teltonika FMC920 + Traccar

Documento vivo del piloto de rastreo GPS en la unidad de prueba. Reúne la configuración del dispositivo, la infraestructura y los hallazgos medidos con datos reales.

Última actualización: 5 de octubre de 2026.

## Resumen de hallazgos

Medido con las posiciones reales de la unidad piloto (25 al 29 de septiembre de 2026):

1. **En movimiento, el FMC920 genera una posición cada 6 segundos** en promedio. Es mucho más denso de lo que necesita el modelo de ETA.
2. **El 13 % de las posiciones están duplicadas** por reenvíos del dispositivo. Traccar las guarda con IDs distintos.
3. **Mientras la unidad se mueve, las posiciones no llegan a Traccar.** Se entregan en bloque horas después (hasta 8 h), con señal celular máxima. Con la configuración actual el seguimiento en tiempo real no es viable.
4. **Una parada de menos de una hora con el motor apagado no deja ningún registro.**

Estos puntos deben resolverse antes de configurar los dispositivos de la flota.

## 1. Dispositivo

| Campo | Valor |
|---|---|
| Modelo | Teltonika FMC920 |
| Conectividad | 4G LTE Cat 1 |
| Vehículo piloto | Volkswagen Tiguan |
| SIM | Telcel |
| IMEI | Termina en 3025 (completo fuera del repositorio) |
| Firmware | 03.29.00.Rev.21 |
| Nombre en Traccar | `tiguan FMC920` |

## 2. Infraestructura

- **Servidor:** AWS EC2 t3.micro, Ubuntu, región us-east-1.
- **Traccar:** interfaz web en el puerto 8082; protocolo Teltonika en el puerto 5027 TCP.
- **IP pública y credenciales:** solo en `.env`, nunca en el código ni en este repositorio. La IP puede cambiar si la instancia se reinicia.

### Seguridad pendiente

Durante el piloto, el Security Group permite tráfico entrante desde `0.0.0.0/0` en los puertos 22 (SSH), 8082 (Traccar web), 5027 (Teltonika) y 5055. Es una configuración de pruebas. Antes de producción hay que restringir el acceso a SSH y a la interfaz web, y evaluar TLS entre el dispositivo y el servidor: hoy el FMC920 se identifica solo con su IMEI, sin cifrado.

### Regla de arquitectura

Traccar es la fuente de telemetría. El backend consume la **API de Traccar** y nunca se acopla a su base de datos interna.

## 3. Configuración actual del FMC920

### Servidor

| Parámetro | Valor |
|---|---|
| Domain/IP | IP pública del EC2 (ver `.env`) |
| Port | 5027 |
| Protocol | TCP |
| TLS Encryption | None |
| Second Server | Disabled |

### Data Acquisition

Home, Roaming y Unknown tienen los mismos valores.

| Modo | Min Period | Min Distance | Min Angle | Min Speed Delta | Min Saved Records | Send Period |
|---|---|---|---|---|---|---|
| On Stop | 3600 s | | | | 1 | 120 s |
| Moving | 300 s | 100 m | 10° | 10 km/h | 1 | 120 s |

### On Demand Tracking

Period 10 s, Duration 600 s, activado por DIN1.

### Generación contra envío

Son dos cosas distintas:

- **Generación:** en movimiento se crea un registro cuando se cumple cualquiera de las condiciones (tiempo, distancia, ángulo o cambio de velocidad).
- **Envío:** `Min Saved Records` y `Send Period` controlan cuándo se mandan al servidor los registros acumulados.

`Send Period = 120 s` no significa una posición cada 2 minutos. Los hallazgos de abajo lo confirman.

## 4. Hallazgos medidos

Todas las consultas están en el anexo y se corren contra la base de desarrollo de Laravel (`database/database.sqlite`).

### 4.1 Frecuencia real de posiciones

Sin duplicados, excluyendo huecos de más de 2 horas (noches y días sin uso):

| Estado | Posiciones | Hueco promedio | Hueco máximo |
|---|---|---|---|
| En movimiento | 1862 | 6 s | 318 s |
| Detenido, motor encendido | 136 | 134 s | 3078 s (51 min) |
| Apagado | 143 | 2072 s (35 min) | cerca de 1 h |

- En movimiento manda el `Min Distance` de 100 m: a velocidad urbana se cumple cada pocos segundos. El máximo de 318 s corresponde al `Min Period` de 300 s.
- Detenido con el motor encendido, las paradas cortas reportan seguido, pero una espera larga pasa a modo On Stop y puede tardar casi una hora en reportar.

### 4.2 Posiciones duplicadas

| Total | Únicas | Duplicadas |
|---|---|---|
| 2462 | 2144 | 318 (13 %) |

Ejemplo, la misma posición guardada dos veces:

| Campo | Registro 1 | Registro 2 |
|---|---|---|
| `traccar_position_id` | 11033 | 11047 |
| `server_time` (UTC) | 19:40:40 | 19:42:08 |
| `attributes.distance` | 47.5 | 0 |

El resto de `attributes` es idéntico. El mismo registro llegó dos veces a Traccar con 88 s de diferencia: es un reenvío del dispositivo, probablemente porque no recibió a tiempo la confirmación del servidor. La segunda copia no aporta información.

### 4.3 Retraso entre generación y llegada

Retraso = `server_time - device_time`.

- **Estacionada:** cada posición llega en 1 o 2 minutos. Normal con `Send Period` de 120 s.
- **Manejando:** las posiciones no llegan durante horas.

Retraso máximo en los días con recorridos:

| Día | Retraso máximo |
|---|---|
| 25 sep | 8.4 h |
| 27 sep | 2.7 h |
| 28 sep | 8.1 h |
| 29 sep | 3.0 h |

Detalle del 25 de septiembre, agrupado por hora de llegada a Traccar (hora Cancún):

| Llegada | Posiciones recibidas | Generadas entre |
|---|---|---|
| 23:00 a 11:00 | 1 por hora | la misma hora |
| 11:00 a 19:00 | ninguna | |
| 19:00 | 515 | 11:25 y 15:10 |
| 20:00 | 652 | 15:10 y 20:44 |
| 21:00 | 1 | 21:44 (normal otra vez) |

Durante casi 8 horas no llegó ninguna posición, y después llegaron 1,167 en dos horas, en orden de la más vieja a la más nueva. El dispositivo sí tiene capacidad de envío; lo que falla es la entrega mientras hay recorridos.

**La señal no es la causa:** el registro del ejemplo de 4.2, generado mientras la unidad se movía, reporta `rssi: 5`, la señal máxima en Teltonika.

### 4.4 Hipótesis descartada: el encendido

Se revisó si el bloqueo dependía de que la unidad estuviera apagada. Actividad del 25 de septiembre (hora Cancún):

- 11:00 a 17:30: recorridos en bloques, con paradas cortas.
- Desde 17:30: apagada y estacionada.

El envío se destrabó entre 19:00 y 21:00, **con la unidad apagada**, y durante las 6 horas encendida y en movimiento no llegó nada. El estado del motor no explica ni el bloqueo ni el desbloqueo. La entrega se recuperó sola, cerca de hora y media después del último recorrido.

**Hipótesis vigente:** el fallo está en el intercambio entre el FMC920 y Traccar. El dispositivo intenta enviar el paquete más viejo de la cola, la entrega no se confirma, lo reintenta (de ahí los duplicados) y todo lo demás queda atorado detrás. Falta confirmarlo con el log de Traccar durante un recorrido (ver pendientes).

### 4.5 Paradas cortas sin registro

Con `On Stop Min Period` de 3600 s, una parada de menos de una hora con el motor apagado no genera ningún registro. El 25 de septiembre hay bloques de 12:00 a 12:30 y de 16:00 a 16:30 sin posiciones. Para el Trip Builder esto significa que no conoce con precisión el fin de un viaje ni el inicio del siguiente.

### 4.6 Atributos confirmados

Del payload real que entrega Traccar en `attributes`:

| Atributo | Ejemplo | Uso |
|---|---|---|
| `ignition` | `true` | Confirmado. Base del Trip Builder. |
| `motion` | `true` | Movimiento según el dispositivo. |
| `odometer` | 263862 | Metros. Candidato para `distance_km` por viaje. |
| `totalDistance` | 9959868.8 | Metros, calculado por Traccar. |
| `distance` | 47.5 | Metros desde la posición anterior, calculado por Traccar. |
| `hours` | 60822000 | Horas de motor, en milisegundos. |
| `power` | 14 | Voltaje del vehículo. |
| `battery` | 4.118 | Voltaje de la batería interna. |
| `sat` | 14 | Satélites en uso. |
| `hdop` | 0.7 | Precisión horizontal (menor es mejor). |
| `rssi` | 5 | Señal celular, escala 0 a 5. |

**Pendiente:** `odometer` indica unos 264 km y `totalDistance` unos 9,960 km. No cuadran. Antes de usar cualquiera para `distance_km` hay que entender qué mide cada uno.


### 4.7 Análisis del log de Traccar (24 al 26 de septiembre)

Traccar registra en `/opt/traccar/logs/tracker-server.log` cada paquete completo que recibe del dispositivo (`<`) y cada respuesta que le devuelve (`>`). Los registros están en UTC y se guardan por día.

Resumen por hora (UTC):

| Hora | Qué pasaba | Conexiones | Mensajes del FMC920 | Posiciones recibidas |
|---|---|---|---|---|
| 09:00 del 25 | Estacionada | 1 | 2 (IMEI y datos) | 1 |
| 17:00 del 25 | Manejando | 30 | 30 (solo IMEI) | 0 |
| 01:00 del 26 | Estacionada, de noche | 16 | 67 | 765 |

Sesión típica durante el manejo:

17:00:40 connected
17:00:40 teltonika < (IMEI)
17:00:40 teltonika > 01
17:01:10 disconnected
17:02:40 connected (90 s después, se repite igual)

Sesión exitosa, con la unidad estacionada:


Hallazgos:

- Durante el manejo, el FMC920 se conecta cada 2 minutos, se identifica y Traccar lo acepta, pero **Traccar nunca registra un paquete de datos**. La conexión se cierra a los **30 segundos exactos**, sin error.
- Una conexión exitosa se mantiene abierta **5 minutos** después de la confirmación.
- Los errores de red (`Connection reset`, `Connection timed out`) se concentran en las ráfagas de entrega: 9 entre 04:32 y 04:48 UTC del 25 y 14 entre 00:52 y 01:37 UTC del 26. Durante las 8 horas de manejo del 25 solo hubo 2.
- El patrón se repite el 24, el 25 y el 26 de septiembre.
- **Corrección:** la conexión de 30 segundos observada al configurar el piloto, que se tomó como comportamiento normal, es en realidad el patrón de falla.

**Hipótesis vigente** (reemplaza la de 4.4): el FMC920 sí envía el paquete de datos, pero no llega completo a Traccar, que solo registra paquetes Teltonika completos. El dispositivo espera la confirmación 30 segundos (tiempo de espera de respuesta del servidor), cierra y reintenta, cada vez con una cola más grande. Estacionada, cada paquete lleva un solo registro y llega sin problema; manejando, con una posición cada 6 segundos, el paquete crece. La posible relación con el tamaño del paquete se confirmará con la captura de tráfico.

Resumen por hora del log (en el servidor):

```bash
sudo awk '
/^20[0-9][0-9]-/      { h = $1 " " substr($2, 1, 2) ":00"; seen[h] = 1 }
/\] connected$/       { c[h]++ }
/\] disconnected$/    { d[h]++ }
/teltonika </         { r[h]++ }
/teltonika >/         { a[h]++ }
/\] id: [0-9]+, time:/ { p[h]++ }
/WARN|ERROR/          { e[h]++ }
END {
  for (k in seen)
    printf "%s UTC  conex:%d  desc:%d  enviados:%d  confirmados:%d  posiciones:%d  errores:%d\n",
      k, c[k], d[k], r[k], a[k], p[k], e[k]
}' /opt/traccar/logs/tracker-server.log.20260925 /opt/traccar/logs/tracker-server.log.20260926 | sort
```

- [ ] Analizar la captura de tráfico del puerto 5027 (`tcpdump` en el servidor, iniciada el 5 de octubre, archivo `~/trayecto.pcap`) junto con el recorrido del 6 de octubre.


## 5. Implicaciones para el sistema

### Sincronización (`traccar:sync-positions`)

- **Pedir a Traccar por hora de llegada al servidor, no por `device_time`.** Las posiciones pueden llegar con horas de retraso y con una `device_time` anterior a la última guardada; un sync basado en `device_time` las perdería.
- **Descartar duplicados.** La idempotencia por `traccar_position_id` no basta, porque Traccar asigna IDs distintos a cada copia. Hay que deduplicar por vehículo, `device_time` y coordenadas.

### Dashboard

Umbral de "Sin señal reciente" según el último estado conocido, en lugar de un valor fijo de 10 minutos:

| Último estado | Hueco máximo medido | Umbral propuesto |
|---|---|---|
| En movimiento | 318 s | 8 min |
| Detenido o apagado | cerca de 1 h | 65 min |

Mientras no se resuelva el retraso de 4.3, el dashboard no puede mostrar la posición actual de una unidad en recorrido.

### Presupuesto de datos

A una posición cada 6 segundos, una unidad que maneje 10 horas al día genera unas 6,000 posiciones diarias, más los reenvíos. La SIM de Teltonika evaluada (500 MB por 5 años) equivale a unos 270 KB diarios. La estimación preliminar queda por encima del presupuesto; falta medir los bytes reales por registro.

## 6. Propuesta de configuración para la flota

**Estado: pendiente de validar en la unidad piloto.** No aplicar a la flota sin antes medir el antes y el después en la Tiguan.

Tres cambios prioritarios:

1. **El encendido y el apagado generan un registro inmediato** (configuración de I/O de Ignition). Resuelve 4.5 y da al Trip Builder el inicio y el fin exactos de cada viaje.
2. **Fuente de movimiento por encendido.** Con el motor encendido y la unidad detenida (por ejemplo, esperando a un cliente), el dispositivo sigue en modo Moving y reporta con regularidad.
3. **Odómetro como parámetro de I/O.** La distancia del viaje sale de la diferencia de odómetro, sin depender de la densidad de posiciones.

Ajuste de periodos:

| Parámetro | Actual | Propuesta | Motivo |
|---|---|---|---|
| Moving, Min Period | 300 s | 60 s | Al menos un punto por minuto en carretera recta. |
| Moving, Min Distance | 100 m | 250 m | Reduce la densidad urbana (hoy un punto cada 6 s). |
| Moving, Min Angle | 10° | 15° | Menos puntos en curvas leves. |
| Moving, Min Speed Delta | 10 km/h | 15 km/h | Menos puntos por cambios normales del tráfico. |
| On Stop, Min Period | 3600 s | 3600 s | Suficiente si el encendido genera su propio registro. |
| Send Period | 120 s | 120 s | Retraso aceptable para traslados largos. |

Los nombres exactos de los parámetros deben confirmarse en el Configurator para el firmware 03.29.

### Plan de validación

1. Línea base con la configuración actual (este documento).
2. Aplicar la propuesta en la Tiguan y usarla normalmente una semana, con al menos un recorrido de prueba que imite un servicio: carretera Cancún a Playa del Carmen y una espera de 20 minutos con el motor encendido.
3. Repetir las consultas del anexo y comparar.
4. Instalar el primer dispositivo de la flota en una unidad real de Feraltar como segundo piloto, una o dos semanas, para validar consumo de datos y comportamiento en operación real.
5. Documentar la configuración final y aplicarla al resto de la flota.

## 7. Pendientes

- [ ] Capturar el log de Traccar durante un recorrido para encontrar la causa del retraso de 4.3:
  `sudo tail -f /opt/traccar/logs/tracker-server.log | tee ~/log-trayecto.txt`
- [ ] Explicar la diferencia entre `odometer` y `totalDistance`.
- [ ] Medir los bytes reales por registro y el consumo diario de datos.
- [ ] Deduplicar posiciones en el sync y limpiar los 318 duplicados existentes.
- [ ] Cambiar el sync para consultar por hora de llegada al servidor.
- [ ] Ajustar los umbrales de "Sin señal reciente" en el dashboard.
- [ ] Validar la propuesta de configuración (sección 6).
- [ ] Hardening del servidor (sección 2).

## 8. SIM para la flota (Teltonika / 1GLOBAL)

Opción en evaluación para las unidades nuevas:

- 500 MB con vigencia de 5 años, pago único, sin mensualidad.
- Multicarrier: se conecta a la red con mejor cobertura disponible.
- Recarga de 500 MB con nueva vigencia de 5 años, gestionada por Teltonika.
- Entregada preactivada, con plataforma de administración y APN proporcionados por Teltonika.
- Precio cotizado: $394 MXN por SIM con el plan incluido.

Al instalarla hay que configurar en el FMC920 el APN que proporcione Teltonika.

## 9. Diagnóstico de comunicación

Ante un problema, revisar en este orden:

1. Alimentación
2. GNSS Fix
3. SIM
4. Datos móviles
5. APN
6. Conectividad
7. AWS Security Group
8. Puerto 5027
9. Traccar
10. Registros AVL

Comprobaciones útiles en el servidor:

```bash
sudo ss -lntp | grep 5027
sudo tail -f /opt/traccar/logs/tracker-server.log
```

En pruebas de escritorio el FMC920 se alimentó con una fuente externa de 12 V DC / 2 A (rojo a positivo, negro a negativo). Para configurarlo por USB, mantener la alimentación externa conectada; no depender solo del USB.

## Anexo: consultas

Se corren desde `backend/`.

### Frecuencia y retraso por estado, sin duplicados

```bash
sqlite3 -header -column database/database.sqlite << 'EOF'
WITH unicas AS (
  SELECT MIN(id) AS id, device_time, MIN(server_time) AS server_time, speed, ignition
  FROM positions
  WHERE vehicle_id = 1 AND data_source = 'real'
  GROUP BY device_time, latitude, longitude
),
g AS (
  SELECT
    speed,
    ignition,
    (julianday(device_time) - julianday(LAG(device_time) OVER (ORDER BY device_time))) * 86400 AS gap_s,
    (julianday(server_time) - julianday(device_time)) * 86400 AS retraso_s
  FROM unicas
)
SELECT
  CASE
    WHEN speed > 0 THEN 'en movimiento'
    WHEN ignition = 1 THEN 'detenido, motor encendido'
    ELSE 'apagado'
  END AS estado,
  COUNT(*) AS posiciones,
  ROUND(AVG(gap_s)) AS gap_prom_s,
  ROUND(MIN(gap_s)) AS gap_min_s,
  ROUND(MAX(gap_s)) AS gap_max_s,
  ROUND(AVG(retraso_s)) AS retraso_prom_s,
  ROUND(MAX(retraso_s)) AS retraso_max_s
FROM g
WHERE gap_s IS NOT NULL AND gap_s < 7200
GROUP BY estado;
EOF
```

### Conteo de duplicados

```bash
sqlite3 -header -column database/database.sqlite << 'EOF'
SELECT
  COUNT(*) AS total_posiciones,
  COUNT(DISTINCT device_time || latitude || longitude) AS posiciones_unicas,
  COUNT(*) - COUNT(DISTINCT device_time || latitude || longitude) AS sobrantes
FROM positions
WHERE vehicle_id = 1 AND data_source = 'real';
EOF
```

### Retraso por hora de generación (hora Cancún)

```bash
sqlite3 -header -column database/database.sqlite << 'EOF'
WITH unicas AS (
  SELECT device_time, MIN(server_time) AS server_time
  FROM positions
  WHERE vehicle_id = 1 AND data_source = 'real'
  GROUP BY device_time, latitude, longitude
)
SELECT
  strftime('%Y-%m-%d %H:00', datetime(device_time, '-5 hours')) AS hora_cancun,
  COUNT(*) AS posiciones,
  ROUND(MIN((julianday(server_time) - julianday(device_time)) * 1440)) AS retraso_min_min,
  ROUND(AVG((julianday(server_time) - julianday(device_time)) * 1440)) AS retraso_prom_min,
  ROUND(MAX((julianday(server_time) - julianday(device_time)) * 1440)) AS retraso_max_min
FROM unicas
GROUP BY hora_cancun
ORDER BY hora_cancun;
EOF
```

### Llegadas a Traccar por hora (un día)

Cambiar las fechas del `WHERE` según el día a revisar (en UTC).

```bash
sqlite3 -header -column database/database.sqlite << 'EOF'
WITH unicas AS (
  SELECT device_time, MIN(server_time) AS server_time
  FROM positions
  WHERE vehicle_id = 1 AND data_source = 'real'
  GROUP BY device_time, latitude, longitude
)
SELECT
  strftime('%Y-%m-%d %H:00', datetime(server_time, '-5 hours')) AS llegada_cancun,
  COUNT(*) AS posiciones_recibidas,
  strftime('%H:%M', MIN(datetime(device_time, '-5 hours'))) AS generada_desde,
  strftime('%H:%M', MAX(datetime(device_time, '-5 hours'))) AS generada_hasta
FROM unicas
WHERE device_time >= '2026-09-25' AND device_time < '2026-09-26 06:00'
GROUP BY llegada_cancun
ORDER BY llegada_cancun;
EOF
```