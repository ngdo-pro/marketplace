---
name: cc:lint
version: "0.2.0"
description: >
  This skill should be used when the user says "/cc:lint", "lint my plugin",
  "review my plugin", "review this skill", "check plugin quality", "audit my plugin",
  "lint best practices", "check skill quality", "lint hooks", "lint commands",
  "review commands", "review agent", "plugin lint", "plugin review", "skill review",
  "review CLAUDE.md", "lint CLAUDE.md", "check my CLAUDE.md", "review rules",
  or wants to lint or review any Claude Code component
  (plugin, skill, command, hook, agent, CLAUDE.md, rules) against best practices.
---

# Claude Code Quality Review

You perform a structured quality review of Claude Code components (plugins, skills, commands, hooks, agents, CLAUDE.md, rules) against established best practices.

Write all output in the same language as the user's input.

---

## Phase 1: Identify Target

$ARGUMENTS

Determine what to review based on the user's input or arguments.

**If a path is provided**, use it directly.

**If no path is provided**, look for a plugin in the current working directory:
1. Check if `.claude-plugin/plugin.json` exists in the current directory
2. If not, check if we're inside a `plugins/{name}/` directory
3. If not, use `AskUserQuestion` to ask the user what they want to review

**Determine the review scope:**
- **Full plugin review**: Target is a plugin root (has `.claude-plugin/plugin.json`)
- **Skill review**: Target is a `SKILL.md` file or a `skills/{name}/` directory
- **Command review**: Target is a `.md` file in a `commands/` directory
- **Hook review**: Target is a `hooks/hooks.json` file or `hooks/` directory
- **Agent review**: Target is a `.md` file in an `agents/` directory
- **CLAUDE.md review**: Target is a `CLAUDE.md` file
- **Rules review**: Target is a `.claude/rules/` directory or a specific rule file within it

---

## Phase 2: Load Best Practices

Read the best practices checklist from `references/best-practices.md` in this skill's directory.

This checklist is your review rubric. Every finding must reference a specific checklist item.

---

## Phase 3: Analyze Target

Read all relevant files for the target scope.

### For a full plugin review, read:
- `.claude-plugin/plugin.json`
- All `skills/*/SKILL.md` files
- All `commands/*.md` files
- `hooks/hooks.json` and all hook scripts (if they exist)
- All `agents/*.md` files (if they exist)
- `README.md` (if it exists)
- Check if the plugin has a matching entry in the marketplace registry (look for `../../.claude-plugin/marketplace.json` relative to the plugin root). If not found, try searching upward for a `.claude-plugin/marketplace.json`. If still not found, skip the marketplace version check.

### For a skill review, read:
- The `SKILL.md` file
- All files in the `references/` directory (if it exists)
- The parent plugin's `plugin.json` for context

### For a command review, read:
- The command `.md` file
- The skill it delegates to (if referenced)

### For a hook review, read:
- `hooks.json`
- All referenced hook scripts
- Check scripts are executable: `ls -la {script_path}`

### For an agent review, read:
- The agent `.md` file

### For a CLAUDE.md review, read:
- The `CLAUDE.md` file
- Check for `.claude/rules/` directory and list its files for context
- Review against §6 (Memory & Context) of the checklist

### For a rules review, read:
- All `.md` files in `.claude/rules/`
- The `CLAUDE.md` file for context
- Review each rule file for clarity, actionability, and scope
- Review against §6 (Memory & Context) of the checklist

---

## Phase 4: Generate Review Report

Produce a structured review report. For each finding, assign a severity:

- **CRITICAL**: Breaks functionality or violates a hard requirement
- **WARNING**: Deviation from best practice that may cause issues
- **INFO**: Suggestion for improvement, not a problem

### Report Structure

Use the template from `references/report-template.md` as a structural guide for the report.

**Rules:**
- Only report findings for checklist items that are applicable to the target scope
- Be specific — reference file names, line numbers, exact field names
- Provide actionable fix instructions, not vague advice
- If everything passes, say so explicitly — don't invent findings
- Count only applicable checks for the score

---

## Phase 5: Qualify Review

Present the review report to the user.

Use `AskUserQuestion`:

```
The review report has been generated.
```

Options:
1. **"Done — I'll fix the issues"** → STOP
2. **"Explain a finding"** → Explain the selected finding in detail with examples, then re-present options
3. **"Auto-fix what you can"** → Apply fixes for issues that have clear, safe resolutions (frontmatter fixes, missing fields, structural issues). Do NOT auto-fix content/design issues. After fixing, re-run the review to show updated results.

Loop until the user selects "Done".

---

## Done

When the user is done, output:

```
Review complete for {target_name}.
```

**STOP here.**
