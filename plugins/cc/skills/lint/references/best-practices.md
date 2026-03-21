# Claude Code Best Practices — Review Checklist

Source: https://github.com/shanraisshan/claude-code-best-practice

---

## 1. Plugin Structure

### plugin.json Manifest
- [ ] Contains `name`, `version`, `description`
- [ ] Version follows semver (e.g., `0.1.0`)
- [ ] Description is concise and explains the plugin's purpose
- [ ] Version matches the corresponding entry in marketplace.json (if applicable)

### Directory Layout
- [ ] `.claude-plugin/plugin.json` exists at plugin root
- [ ] Skills in `skills/{skill-name}/SKILL.md`
- [ ] Commands in `commands/{command-name}.md`
- [ ] Hooks in `hooks/hooks.json` + scripts
- [ ] Agents in `agents/{agent-name}.md` (if any)
- [ ] Reference files in `skills/{skill-name}/references/` (if any)
- [ ] No orphan files — every file serves a documented purpose

### Naming
- [ ] Plugin name is lowercase, hyphenated
- [ ] Skill names are lowercase, hyphenated
- [ ] Command names are lowercase, hyphenated
- [ ] Skill names prefixed with plugin name using colon (e.g., `plugin:skill`)

---

## 2. Skills (SKILL.md)

### Frontmatter
- [ ] Has YAML frontmatter with `---` delimiters
- [ ] Contains `name` field matching `{plugin}:{skill}` pattern
- [ ] Contains `version` field (string, e.g., `"1.0"`)
- [ ] Contains `description` field — multi-line, rich with trigger phrases
- [ ] Description includes natural language triggers (e.g., "when the user says X, Y, Z")
- [ ] Description includes slash command triggers (e.g., "/plugin:skill")

### Description Quality (Critical for Triggering)
- [ ] Description is specific enough to trigger on relevant requests
- [ ] Description is narrow enough to NOT trigger on unrelated requests
- [ ] Includes 3+ trigger phrases covering different ways users might ask
- [ ] Mentions key verbs and nouns the user would use
- [ ] Does NOT use vague/generic terms that could match too broadly

### Content Structure
- [ ] Has a clear title (H1)
- [ ] Includes `$ARGUMENTS` placeholder if it accepts arguments
- [ ] Organized in numbered phases with clear decision points
- [ ] Each phase has a clear purpose and exit condition
- [ ] Uses markdown headers (##) for phases
- [ ] Has a "Done" or completion section with clear STOP instruction

### User Interaction
- [ ] Uses `AskUserQuestion` tool for user input (not assumptions)
- [ ] Has validation/qualification loops where user can iterate or validate
- [ ] Clearly marks CRITICAL steps that must not be skipped
- [ ] Provides options (numbered or named) for user decisions
- [ ] Writes all output in the user's language (explicit instruction present)

### Best Practices
- [ ] Modular and focused on a single workflow
- [ ] Supports progressive disclosure (doesn't dump everything at once)
- [ ] References templates in `references/` directory when applicable
- [ ] Templates are treated as guides, not rigid formats
- [ ] No hardcoded paths (uses relative paths or variables)
- [ ] File operations use proper tools (Write, Read, Edit — not Bash)
- [ ] Idempotent operations where possible

---

## 3. Commands

### Frontmatter
- [ ] Has YAML frontmatter with `---` delimiters
- [ ] Contains `description` field (concise, one line)
- [ ] Contains `argument-hint` field with placeholder (e.g., `"[topic]"`)

### Content
- [ ] Includes `$ARGUMENTS` to pass user input
- [ ] Delegates to a skill (e.g., "Load and execute the `plugin:skill` skill")
- [ ] Minimal logic — commands are entry points, not workflows
- [ ] Clear, descriptive naming matching `/command-name` invocation

### Anti-patterns
- [ ] Does NOT contain complex logic (should be in skills)
- [ ] Does NOT duplicate skill instructions
- [ ] Does NOT hardcode values that should come from arguments

---

## 4. Hooks

### hooks.json Structure
- [ ] Has `description` field explaining the hook's purpose
- [ ] Has `hooks` object with valid event keys
- [ ] Valid events: `PreToolUse`, `PostToolUse`, `Stop`, `SubagentStop`, `SessionStart`, `SessionEnd`, `UserPromptSubmit`, `PreCompact`, `Notification`
- [ ] Each event has a `matcher` (tool name to match)
- [ ] Each hook entry has `type` (usually `"command"`)
- [ ] Each hook entry has `command` with script path
- [ ] Uses `${CLAUDE_PLUGIN_ROOT}` for script paths
- [ ] Has `timeout` set (recommended: 5-10 seconds)

### Hook Scripts
- [ ] Reads JSON from stdin (`tool_name`, `tool_input`, `session_id`)
- [ ] Uses stderr for messages (not stdout)
- [ ] Exit code 0 = allow execution
- [ ] Exit code 2 = block execution
- [ ] Script is executable (`chmod +x`)
- [ ] Has proper shebang line (`#!/bin/bash` or `#!/usr/bin/env bash`)
- [ ] Handles missing/malformed input gracefully
- [ ] Deterministic behavior (no agentic decisions)

---

## 5. Agents

### Frontmatter
- [ ] Has clear `name` field
- [ ] Has `description` field explaining when to use the agent
- [ ] Specifies `tools` list (which tools the agent can use)
- [ ] Specifies `model` if different from default

### Design
- [ ] Feature-specific rather than generic role-based (e.g., NOT "QA agent" but "test-runner agent")
- [ ] Has defined scope and boundaries
- [ ] Appropriate tool permissions (principle of least privilege)
- [ ] Clear purpose — one agent, one job

---

## 6. Memory & Context (CLAUDE.md)

- [ ] CLAUDE.md is under 200 lines (60 lines optimal)
- [ ] Instructions are actionable and specific
- [ ] No redundant information derivable from code
- [ ] Uses `.claude/rules/` for splitting large instruction sets
- [ ] Avoids "MUST" overuse — prioritize what truly matters

---

## 7. Cross-cutting Concerns

### Versioning
- [ ] Plugin version in plugin.json follows semver
- [ ] Plugin version matches marketplace.json entry
- [ ] Version bumped when changes are made

### Language & Localization
- [ ] Skills contain explicit instruction to match user's language
- [ ] No hardcoded language — output adapts to user input

### Security
- [ ] No secrets or credentials in plugin files
- [ ] Hook scripts validate input before processing
- [ ] Permissions are scoped appropriately in settings

### Documentation
- [ ] README.md exists for the plugin (optional but recommended)
- [ ] Skill descriptions are self-documenting
- [ ] Complex hooks have inline comments

---

## 8. Common Anti-patterns

- **Over-orchestration**: Complex command → agent → skill chains when vanilla Claude suffices
- **Fat commands**: Commands containing workflow logic instead of delegating to skills
- **Generic agents**: Role-based agents (e.g., "backend agent") instead of task-specific ones
- **Missing validation loops**: Skills that generate output without user qualification
- **Hardcoded paths**: Using absolute paths instead of `${CLAUDE_PLUGIN_ROOT}` or relative paths
- **Missing STOP instructions**: Skills that don't clearly indicate when to stop
- **Autonomous invocation of interactive skills**: Skills that require user input but allow model invocation
- **Duplicated instructions**: Same logic in both command and skill
- **Missing error handling in hooks**: Scripts that don't handle malformed stdin
- **Ignoring context window**: Skills that dump too much information at once instead of progressive disclosure
