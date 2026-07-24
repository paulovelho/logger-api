#!/usr/bin/env bash
# Always run from the project root, wherever this script is called from.
cd "$(dirname "$0")/.."

export $(grep LOG_PATH .env | xargs) &&
	docker compose up -d --build &&
	docker compose logs -f guia_lol-logger | tee -a "$LOG_PATH"
