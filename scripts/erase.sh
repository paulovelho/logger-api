#!/usr/bin/env bash
# Always run from the project root, wherever this script is called from.
cd "$(dirname "$0")/.."

echo "WARNING: this will permanently erase ALL logged data."
read -r -p 'Type "erase" to confirm: ' confirm

if [ "$confirm" != "erase" ]; then
	echo "Aborted. Nothing was deleted."
	exit 1
fi

docker compose exec -T mongo mongosh logger --quiet --eval 'db.dropDatabase()' &&
	echo "Database erased."
