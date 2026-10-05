#!/usr/bin/env bash
# Local Docker only: build + start the PHP app and MariaDB, then follow the app logs.
cd "$(dirname "$0")/.." &&
	docker compose up -d --build &&
	docker compose logs -f logger_php
