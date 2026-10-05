#!/usr/bin/env bash
# Run ON THE SERVER: pulls latest code and refreshes PHP dependencies.
# Production runs on the host's web server (see deploy.md), not Docker, so
# there is nothing to restart; config.json and PHP files are read per request.
set -e

# Always run from the project root, wherever this script is called from.
cd "$(dirname "$0")/.."

echo "Pulling latest changes..."
git pull

echo "Installing PHP dependencies..."
(cd src && composer install --no-dev --no-interaction)

echo "✅ logger updated."
