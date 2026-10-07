#!/usr/bin/env bash
# Runs the PHPUnit suite inside the logger container (the integration tests need its DB and
# call the API on http://localhost). Needs dev dependencies: (cd src && composer install).
# Usage: ./scripts/test.sh [phpunit args]   e.g. --filter BatchTest
# LOGGER_CONTAINER (default logger_php) and LOGGER_TEST_SERVICE (default: first active,
# non-readonly service in config.json) can be overridden.
cd "$(dirname "$0")/.." || exit 1
docker exec -e LOGGER_TEST_SERVICE -w /var/www/logger/src "${LOGGER_CONTAINER:-logger_php}" vendor/bin/phpunit "$@"
