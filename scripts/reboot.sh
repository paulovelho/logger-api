#!/usr/bin/env bash
# Local Docker only: rebuild + restart, return once /health-check answers.
cd "$(dirname "$0")/.." || exit 1
PORT=$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2)
PORT=${PORT:-3002}

docker compose down &&
	docker compose up -d --build || exit 1

for _ in $(seq 1 30); do
	if curl -fs "http://localhost:$PORT/health-check" >/dev/null; then
		echo "Ready on http://localhost:$PORT"
		exit 0
	fi
	sleep 1
done
echo "Not answering on http://localhost:$PORT/health-check after 30s — check: docker compose logs logger_php"
exit 1
