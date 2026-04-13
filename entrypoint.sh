#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp -n .env.example .env 2>/dev/null || true
    if ! grep -q "^APP_KEY=base64:" .env; then
        php artisan key:generate || true
    fi
fi
wait_for_db() {
    echo "Waiting for database connection..."
    for i in $(seq 1 30); do
        if php artisan db:monitor > /dev/null 2>&1; then
            echo "Database is ready!"
            return 0
        fi
        sleep 2
    done
}

if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "serve" ]; then
    wait_for_db
    echo "Running migrations and seeders..."
    php artisan migrate:fresh --seed --force
fi

if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "horizon" ]; then
    wait_for_db
    echo "Waiting purely for migrations to complete..."
    sleep 15
fi

exec "$@"
