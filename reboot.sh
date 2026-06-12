export $(grep LOG_PATH .env | xargs) &&
	docker compose down &&
	docker compose up -d --build &&
	docker compose logs -f guia_lol-logger | tee -a "$LOG_PATH"
