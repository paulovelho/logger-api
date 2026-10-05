#!/usr/bin/env bash
# Erases ALL logs (TRUNCATE logs) in this instance's database.
# Credentials come from src/configs/magrathea.conf: the active section (general/use_environment),
# or the section given as the first argument:   ./erase.sh [dev|production|...]
# "$=VAR" values are read from the environment (and from .env, if present).
set -euo pipefail
cd "$(dirname "$0")"

CONF=src/configs/magrathea.conf
[[ -f "$CONF" ]] || { echo "Error: $CONF not found"; exit 1; }
[[ -f .env ]] && set -a && . ./.env && set +a

CONF_VALUES=$(php -r '
	$c = parse_ini_file($argv[1], true);
	$s = $argv[2] !== "" ? $argv[2] : ($c["general"]["use_environment"] ?? "");
	if (!isset($c[$s])) { fwrite(STDERR, "section [$s] not found\n"); exit(1); }
	$v = fn($k) => (is_string($c[$s][$k] ?? null) && str_starts_with($c[$s][$k], "$=")) ? getenv(substr($c[$s][$k], 2)) : ($c[$s][$k] ?? "");
	echo implode(" ", array_map("base64_encode", [$s, $v("db_host"), $v("db_name"), $v("db_user"), $v("db_pass")]));
' "$CONF" "${1:-}")
read -r SECTION DB_H DB_N DB_U DB_P <<<"$CONF_VALUES"
d() { echo "$1" | base64 -d; }
SECTION=$(d "$SECTION"); DB_H=$(d "$DB_H"); DB_N=$(d "$DB_N"); DB_U=$(d "$DB_U"); DB_P=$(d "$DB_P")

echo "WARNING: this will permanently erase ALL logs in database '$DB_N' on '$DB_H' (section [$SECTION])."
read -r -p 'Type "erase" to confirm: ' confirm
if [[ "$confirm" != "erase" ]]; then
	echo "Aborted. Nothing was deleted."
	exit 1
fi

# Local Docker: the DB has no published port, so go through the container.
if docker compose ps --services --status running 2>/dev/null | grep -qx logger_db && [[ "$DB_H" == "logger_db" ]]; then
	docker compose exec -T -e MYSQL_PWD="$DB_P" logger_db mariadb -u"$DB_U" "$DB_N" -e 'TRUNCATE TABLE logs'
else
	MYSQL_PWD="$DB_P" mysql -h"$DB_H" -u"$DB_U" "$DB_N" -e 'TRUNCATE TABLE logs'
fi
echo "Logs erased."
