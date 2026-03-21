# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What is this repo

A collection of personal Claude Code plugins. Each plugin lives in `plugins/{name}/` and follows the Claude Code plugin structure:

- `.claude-plugin/plugin.json` — manifest (name, version, description)
- `skills/{skill-name}/SKILL.md` — skill definitions (YAML frontmatter + markdown instructions)
- `skills/{skill-name}/references/` — templates and reference files used by skills
- `commands/{command-name}.md` — slash commands (YAML frontmatter + markdown)
- `hooks/hooks.json` + scripts — event-driven hooks (PreToolUse, SessionStart, etc.)

## Plugins

| Plugin | Purpose |
|--------|---------|
| **os** | Productivity OS — ideas, activity recaps, and todos in Obsidian (`~/vault/OS/`) |
| **spec** | Spec-driven design — 3-step workflow: `/spec:start` → `/spec:design` → `/spec:prompt` |
| **workflow** | Dev workflow — Linear ticket automation (`/workflow:ticket`) and feature interviews |
| **skema** | Architecture diagrams — generate and validate DSL files with auto-validation hook |
| **cc** | Claude Code linter — reviews plugins, skills, commands, hooks, agents, CLAUDE.md, and rules against best practices (`/cc:lint`) |
| **rchiv** | Architecture boundary mapper — generates and maintains `.rchiv.json` files for service boundaries |
| **boundary** | PHP boundary extractor — deterministic AST-based extraction of entries/outputs (`/boundary:scan`, `/boundary:verify`) |
| **agent-browser** | Browser automation — navigate, interact with, and extract data from web pages (`/agent-browser:browse`) |

## Conventions

- All user-facing text must match the user's language (skills contain explicit instructions for this)
- Templates in `references/` are structural guides, not rigid formats
- Hook scripts receive JSON on stdin (`tool_name`, `tool_input`, `session_id`) and use stderr for messages, exit 0 to allow, exit 2 to block
- The spec plugin uses a `specs/{slug}/` directory pattern for artifacts (requirements.md, design.md, prompt.md), each with a `Status: Draft | Validated` header

## When modifying plugins

- Use the `plugin-dev:*` skills and agents when available (skill-development, hook-development, plugin-validator, etc.)
- After creating or modifying a skill, run the skill-reviewer agent
- After modifying plugin structure, run the plugin-validator agent
- **Versioning**: when updating a plugin, bump the version in **both** places:
  1. `plugins/{name}/.claude-plugin/plugin.json` — the plugin's own manifest
  2. `.claude-plugin/marketplace.json` — the marketplace registry (matching entry in the `plugins` array)

## Renaming a plugin

See `.claude/rules/renaming-plugin.md` for the full procedure.
