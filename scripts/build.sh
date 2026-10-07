#!/usr/bin/env bash
# Copies the module-root `version` into src/, where /version reads it first.
# Instances that only get src/ deployed need this; restart.sh runs it on every update.
set -euo pipefail

# Always run from the project root, wherever this script is called from.
cd "$(dirname "$0")/.."

cp version src/version
echo "version $(cat version) copied to src/version"
