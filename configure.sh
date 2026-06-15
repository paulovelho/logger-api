#!/usr/bin/env bash
set -euo pipefail

CONFIG="$(dirname "$0")/config.json"

# Validate config.json
if [[ ! -f "$CONFIG" ]]; then
  echo "Error: config.json not found at $CONFIG"
  exit 1
fi

if ! python3 -c "import json, sys; json.load(open('$CONFIG'))" 2>/dev/null; then
  echo "Error: config.json is not valid JSON"
  exit 1
fi

USERS=$(python3 -c "import json; d=json.load(open('$CONFIG')); print(len(d.get('users', [])))")
echo "config.json is valid. Currently has $USERS user(s)."
echo ""

# Show existing users
python3 -c "
import json
d = json.load(open('$CONFIG'))
for u in d.get('users', []):
    print(f\"  - {u['userId']} ({u.get('name', 'no name')})\")
"
echo ""

# Ask to add a new entry
read -r -p "Add a new service? [y/N] " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
  echo "Nothing changed."
  exit 0
fi

echo ""

# userId
while true; do
  read -r -p "userId: " NEW_ID
  if [[ -z "$NEW_ID" ]]; then
    echo "userId cannot be empty."
    continue
  fi
  EXISTS=$(python3 -c "
import json
d = json.load(open('$CONFIG'))
print('yes' if any(u['userId'] == '$NEW_ID' for u in d.get('users', [])) else 'no')
")
  if [[ "$EXISTS" == "yes" ]]; then
    echo "A user with userId '$NEW_ID' already exists. Choose a different one."
    continue
  fi
  break
done

# secret (suggest random if blank)
SUGGESTED=$(python3 -c "import secrets, string; print(secrets.token_urlsafe(24))")
read -r -p "secret [$SUGGESTED]: " NEW_SECRET
if [[ -z "$NEW_SECRET" ]]; then
  NEW_SECRET="$SUGGESTED"
fi

# name
read -r -p "name: " NEW_NAME

echo ""

# Update config.json
python3 - <<PYEOF
import json

config_path = '$CONFIG'
with open(config_path) as f:
    d = json.load(f)

d.setdefault('users', []).append({
    'userId': '$NEW_ID',
    'secret': '$NEW_SECRET',
    'name': '$NEW_NAME',
})

with open(config_path, 'w') as f:
    json.dump(d, f, indent=2)
    f.write('\n')

print(f"Added '{NEW_NAME}' (userId: '$NEW_ID') to config.json")
PYEOF
