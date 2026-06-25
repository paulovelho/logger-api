#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env"

# ── 1. Check .env ────────────────────────────────────────────────────────────
if [[ ! -f "$ENV_FILE" ]]; then
  echo "Error: .env not found at $ENV_FILE — aborting."
  exit 1
fi

source "$ENV_FILE"

: "${DB_HOST:?DB_HOST is not set in .env}"
: "${DB_PORT:?DB_PORT is not set in .env}"
: "${DB_USER:?DB_USER is not set in .env}"
: "${DB_PASSWORD:?DB_PASSWORD is not set in .env}"
: "${DB_NAME:?DB_NAME is not set in .env}"

echo "Using database: $DB_NAME on $DB_HOST:$DB_PORT as user '$DB_USER'"
echo ""

# ── 2. Prompt for MySQL root credentials ─────────────────────────────────────
read -r -p "MySQL root user [root]: " ROOT_USER
ROOT_USER="${ROOT_USER:-root}"
read -r -s -p "MySQL root password (leave blank if passwordless): " ROOT_PASS
echo ""

if [[ -z "$ROOT_PASS" ]]; then
  MYSQL_CMD="mysql -h $DB_HOST -P $DB_PORT -u $ROOT_USER"
else
  MYSQL_CMD="mysql -h $DB_HOST -P $DB_PORT -u $ROOT_USER -p$ROOT_PASS"
fi

# ── 3. Create DB + user + permissions ────────────────────────────────────────
echo "Creating database and user..."

$MYSQL_CMD <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

echo "Database and user ready."
echo ""

# ── 4. Apply schema ───────────────────────────────────────────────────────────
SCHEMA="$SCRIPT_DIR/database/schema.sql"
echo "Applying schema from $SCHEMA..."
$MYSQL_CMD "$DB_NAME" < "$SCHEMA"
echo "Schema applied."
echo ""

# ── 5. Run configure.sh ───────────────────────────────────────────────────────
echo "Running configure.sh to set up service users..."
bash "$SCRIPT_DIR/configure.sh"
