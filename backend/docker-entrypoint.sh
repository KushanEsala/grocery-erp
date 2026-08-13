#!/bin/sh
set -eu

php artisan config:clear

until php -r '
$dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s",
    getenv("DB_HOST"),
    getenv("DB_PORT"),
    getenv("DB_DATABASE")
);
new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
' >/dev/null 2>&1; do
    echo "Waiting for MySQL..."
    sleep 2
done

php artisan migrate --force

if [ "${RUN_DATABASE_SEEDER:-false}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan optimize:clear
php artisan config:cache
php artisan route:cache

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
