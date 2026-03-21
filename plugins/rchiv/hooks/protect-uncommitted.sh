#!/usr/bin/env bash
set -euo pipefail

# PreToolUse hook: block writes to .rchiv.json if it has uncommitted changes
INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty')

# Only act on .rchiv.json files
if [[ "$FILE_PATH" != *.rchiv.json ]]; then
  exit 0
fi

# New files have no uncommitted changes
if [[ ! -f "$FILE_PATH" ]]; then
  exit 0
fi

# Check for uncommitted changes (staged or unstaged)
UNSTAGED=$(git diff --name-only -- "$FILE_PATH" 2>/dev/null || true)
STAGED=$(git diff --cached --name-only -- "$FILE_PATH" 2>/dev/null || true)

if [[ -n "$UNSTAGED" || -n "$STAGED" ]]; then
  jq -n '{
    hookSpecificOutput: {
      permissionDecision: "deny",
      permissionDecisionReason: "Le fichier .rchiv.json a des modifications non committées. Committe ou stash les changements avant de le modifier."
    }
  }'
fi
