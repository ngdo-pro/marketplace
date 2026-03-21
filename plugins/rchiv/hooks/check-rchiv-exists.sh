#!/usr/bin/env bash
set -euo pipefail

# UserPromptSubmit hook: check .rchiv.json exists before update/upgrade/explain
INPUT=$(cat)
PROMPT=$(echo "$INPUT" | jq -r '.user_prompt // empty')
CWD=$(echo "$INPUT" | jq -r '.cwd // empty')

# Only act on rchiv commands that require an existing .rchiv.json
if ! echo "$PROMPT" | grep -qE '/rchiv:(update|upgrade|explain|fix)'; then
  exit 0
fi

# Check if .rchiv.json exists
if [[ -f "${CWD}/.rchiv.json" ]]; then
  exit 0
fi

# Also check legacy .rchiv file
if [[ -f "${CWD}/.rchiv" ]]; then
  exit 0
fi

COMMAND=$(echo "$PROMPT" | grep -oE '/rchiv:(update|upgrade|explain|fix)' | head -1)

echo "Aucun fichier .rchiv.json trouvé à la racine du projet. Dis au dev de lancer \`/rchiv:config\` puis \`/rchiv:init\` d'abord, puis STOP. N'exécute pas la commande ${COMMAND}."
