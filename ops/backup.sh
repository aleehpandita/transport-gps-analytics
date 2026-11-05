#!/usr/bin/env bash
# Respaldo diario de EkMira a S3. Lo corre el cron de root a las 03:17 de Cancún:
#   17 8 * * * /var/www/ekmira/ops/backup.sh >> /var/log/ekmira-backup.log 2>&1
#
# Qué respalda:
#   - La base del panel (SQLite), con .backup: copia consistente aunque el cron y el
#     panel la estén usando en ese momento.
#   - Configuración: .env de Laravel, traccar.xml y los sitios de Nginx.
# La base de Traccar (H2) no: no se puede copiar bien mientras Traccar corre, y todas sus
# posiciones ya están en la base del panel. Se respalda cuando pase a PostgreSQL.
#
# El bucket se lee de /etc/ekmira-backup.conf (BUCKET=nombre), fuera del repo.
# Las copias de más de 30 días las borra S3 con una regla de ciclo de vida.
set -euo pipefail

source /etc/ekmira-backup.conf
: "${BUCKET:?Falta BUCKET en /etc/ekmira-backup.conf}"

APP=/var/www/ekmira/backend
WORK=/var/backups/ekmira
STAMP=$(date -u +%Y-%m-%d_%H%M)
DAY=$(date -u +%Y/%m/%d)
mkdir -p "$WORK"

echo "== $(date -u '+%F %T') UTC: respaldo $STAMP"

# 1. Base del panel: copia consistente y revisión de integridad antes de subirla
DB="$WORK/ekmira_$STAMP.sqlite"
sqlite3 "$APP/database/database.sqlite" ".backup '$DB'"
CHECK=$(sqlite3 "$DB" "PRAGMA integrity_check;")
if [ "$CHECK" != "ok" ]; then
    echo "ERROR: la copia no pasó integrity_check: $CHECK"
    exit 1
fi
POSITIONS=$(sqlite3 "$DB" "SELECT COUNT(*) FROM positions;")
gzip -9 "$DB"

# 2. Configuración
CONF="$WORK/config_$STAMP.tar.gz"
tar -czf "$CONF" \
    "$APP/.env" \
    /opt/traccar/conf/traccar.xml \
    /etc/nginx/sites-available \
    2>/dev/null

# 3. Subir
aws s3 cp "$DB.gz" "s3://$BUCKET/$DAY/" --only-show-errors
aws s3 cp "$CONF" "s3://$BUCKET/$DAY/" --only-show-errors
echo "Subido: $(du -h "$DB.gz" | cut -f1) base ($POSITIONS posiciones) y $(du -h "$CONF" | cut -f1) configuración"

# 4. En el servidor solo se quedan las de los últimos 3 días
find "$WORK" -type f -mtime +3 -delete
echo "Listo."
