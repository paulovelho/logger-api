#!/usr/bin/env bash
# Local Docker only: rebuild + restart, return once /health-check answers with the database up.
# The route answers 200 even with "database":"fail", and logger_db restarts too, so the body is checked.
cd "$(dirname "$0")/.." || exit 1
PORT=$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2)
PORT=${PORT:-3002}

docker compose down &&
	docker compose up -d --build || exit 1

for _ in $(seq 1 30); do
	if curl -fs "http://localhost:$PORT/health-check" | grep -q '"database":"ok"'; then
		echo "Ready on http://localhost:$PORT"
		exit 0
	fi
	sleep 1
done
echo "Not ready after 30s: http://localhost:$PORT/health-check isn't answering (PHP), or its database isn't \"ok\" yet (MariaDB)."
echo "Check: docker compose logs logger_php logger_db"
exit 1
