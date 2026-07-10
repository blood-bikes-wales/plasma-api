#!/usr/bin/env bash
set -euo pipefail

# Ensure that Docker is running...
if ! docker info > /dev/null 2>&1; then
    echo "Docker is not running." >&2
    exit 1
fi

# Install Composer dependencies using a temporary container, so PHP and
# Composer are not required on the host. The app itself runs on Sail's
# PHP runtime; this image is only used to bootstrap the vendor directory.
docker run --rm \
    --pull=always \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd)":/var/www/html \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

# Create the environment file and generate the application key if needed...
if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=.\+' .env; then
    docker run --rm \
        -u "$(id -u):$(id -g)" \
        -v "$(pwd)":/var/www/html \
        -w /var/www/html \
        laravelsail/php84-composer:latest \
        php artisan key:generate --ansi
fi

# Boot the application via Sail and run the database migrations...
./vendor/bin/sail up -d --wait --wait-timeout 120
./vendor/bin/sail artisan migrate

BOLD='\033[1m'
NC='\033[0m'

echo ""
echo -e "${BOLD}Plasma API is running at http://localhost${NC} (health check at /up, API routes under /api)."
echo -e "Stop it with ${BOLD}./vendor/bin/sail down${NC}."
