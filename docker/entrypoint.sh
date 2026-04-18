#!/bin/sh
set -eu

cd /host

echo "[demo] waiting for postgres..."
until pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-escalated}" >/dev/null 2>&1; do
    sleep 1
done

echo "[demo] dropping + recreating schema"
php bin/console doctrine:database:drop --force --if-exists --no-interaction || true
php bin/console doctrine:database:create --if-not-exists --no-interaction

echo "[demo] running migrations"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[demo] syncing schema for App entities"
php bin/console doctrine:schema:update --force --complete --no-interaction

echo "[demo] loading demo fixtures"
php bin/console doctrine:fixtures:load --no-interaction --append

echo "[demo] clearing cache"
php bin/console cache:clear --no-interaction

echo "[demo] ready — landing page: http://localhost:${APP_PORT:-8000}/demo"
echo "[demo] mailpit UI: http://localhost:${MAILPIT_PORT:-8025}"

exec "$@"
