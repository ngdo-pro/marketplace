#!/usr/bin/env bash
# PreToolUse hook: detect existing specs and inject context before spec skills
# Reads JSON from stdin, checks if the Skill tool is invoking a spec skill,
# then scans specs/ for existing work.

set -euo pipefail

INPUT=$(cat)

# Extract skill name from tool_input
SKILL=$(echo "$INPUT" | jq -r '.tool_input.skill // empty' 2>/dev/null)

# Only run for spec skills
case "$SKILL" in
  spec:start|spec:design|spec:prompt|spec) ;;
  *) exit 0 ;;
esac

# Find specs directory (check common locations)
SPECS_DIR=""
for dir in "specs" "spec"; do
  if [ -d "$dir" ]; then
    SPECS_DIR="$dir"
    break
  fi
done

# No specs directory found — nothing to report
if [ -z "$SPECS_DIR" ]; then
  exit 0
fi

# Scan for existing specs
OUTPUT=""
for slug_dir in "$SPECS_DIR"/*/; do
  [ -d "$slug_dir" ] || continue
  slug=$(basename "$slug_dir")

  requirements=""
  design=""
  prompt=""

  if [ -f "$slug_dir/requirements.md" ]; then
    status=$(grep -oP 'Status:\s*\K\w+' "$slug_dir/requirements.md" 2>/dev/null || echo "Unknown")
    requirements="requirements.md ($status)"
  fi

  if [ -f "$slug_dir/design.md" ]; then
    status=$(grep -oP 'Status:\s*\K\w+' "$slug_dir/design.md" 2>/dev/null || echo "Unknown")
    design="design.md ($status)"
  fi

  if [ -f "$slug_dir/prompt.md" ]; then
    prompt="prompt.md (exists)"
  fi

  if [ -n "$requirements" ] || [ -n "$design" ] || [ -n "$prompt" ]; then
    entry="- **$slug**: "
    parts=()
    [ -n "$requirements" ] && parts+=("$requirements")
    [ -n "$design" ] && parts+=("$design")
    [ -n "$prompt" ] && parts+=("$prompt")
    entry+=$(IFS=', '; echo "${parts[*]}")
    OUTPUT+="$entry\n"
  fi
done

# If we found existing specs, inject the context
if [ -n "$OUTPUT" ]; then
  echo -e "[spec-hook] Existing specs detected:\n$OUTPUT\nConsider asking the user if they want to resume one of these." >&2
fi

# Always allow the tool to proceed
exit 0
