#!/usr/bin/env bash
# Run ON THE SERVER: pulls latest code, rebuilds/restarts the container,
# and confirms it's actually running afterwards.
set -e

CONTAINER="guia_lol-logger"

echo "Pulling latest changes..."
git pull

echo "Restarting..."
docker compose down
docker compose up -d --build

echo "Checking container status..."
sleep 2
if [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ]; then
	echo "✅ $CONTAINER is running."
else
	echo "❌ $CONTAINER is NOT running." >&2
	exit 1
fi

exit 0
