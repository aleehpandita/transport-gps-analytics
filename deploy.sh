#!/usr/bin/env bash
# Sube a producción lo último de main. Se corre en el servidor, como ubuntu:
#   bash /var/www/ekmira/deploy.sh
#
# El código es de ubuntu; storage, bootstrap/cache y database son de www-data (el usuario
# de PHP y del cron), por eso artisan se corre con sudo -u www-data.
set -euo pipefail

cd /var/www/ekmira
echo "== Código"
git pull --ff-only
git log --oneline -1

echo "== Backend"
cd backend
# --no-scripts: los scripts de composer escriben en bootstrap/cache, que es de www-data;
# se corren después como www-data
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
sudo -u www-data php artisan package:discover --ansi
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache

echo "== Frontend"
cd ../frontend
npm ci --no-audit --no-fund
NODE_OPTIONS=--max-old-space-size=512 npm run build

echo "== Servicios"
sudo systemctl reload php8.3-fpm
echo "Listo."
