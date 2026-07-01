#!/usr/bin/env bash
set -euo pipefail

COMPOSE="docker compose -f infra/compose.yaml"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

cd "$PROJECT_DIR"

echo "==> Checking .env..."
if [ ! -f .env ]; then
  echo "ERROR: .env file not found. Copy .env.example and fill in the values."
  exit 1
fi

echo "==> Pulling latest code..."
git pull

echo "==> Building images..."
$COMPOSE build --no-cache api worker web

echo "==> Starting database and redis..."
$COMPOSE up -d db redis

echo "==> Waiting for database to be healthy..."
until $COMPOSE exec -T db pg_isready -U eshopeo > /dev/null 2>&1; do
  sleep 1
done

echo "==> Running migrations..."
$COMPOSE run --rm api alembic upgrade head

echo "==> Deploying API, worker and web..."
$COMPOSE up -d --force-recreate api worker web

echo "==> Cleaning up old images..."
docker image prune -f

echo ""
echo "==> Deploy complete. Status:"
$COMPOSE ps
