#!/usr/bin/env bash
# Deploy logger/ by triggering the remote restart flow on the server.
#
# Like crawler, logger isn't pushed from local — the server has its own git
# clone. This SSHes in and runs scripts/restart.sh there (git pull + docker compose
# down/up --build + a check that the container is actually running
# afterwards).
#
# Usage: ./scripts/deploy.sh   (works whether run directly or via the root deploy.sh)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONFIG_FILE="$ROOT/docker/deploy-config.sh"

if [ ! -f "$CONFIG_FILE" ]; then
	echo "Missing $CONFIG_FILE (copy deploy-config.sh.sample and fill it in)." >&2
	exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG_FILE"

if [ -z "${SSH_USER:-}" ] || [ -z "${SSH_SERVER:-}" ] || [ -z "${SSH_LOCATION:-}" ]; then
	echo "deploy-config.sh is missing one of: SSH_USER, SSH_SERVER, SSH_LOCATION." >&2
	exit 1
fi

# Project root on the server (where logger is git-cloned and docker compose runs).
REMOTE_PROJECT_ROOT="$SSH_LOCATION/logger"

echo "▶ Deploying logger on $SSH_USER@$SSH_SERVER:$REMOTE_PROJECT_ROOT"
ssh "$SSH_USER@$SSH_SERVER" "cd '$REMOTE_PROJECT_ROOT' && ./scripts/restart.sh"

ssh "$SSH_USER@$SSH_SERVER" "date '+%Y-%m-%dT%H:%M:%S' > '$REMOTE_PROJECT_ROOT/last_deploy.md'"

echo "Done."
