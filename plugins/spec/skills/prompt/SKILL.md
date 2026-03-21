---
name: spec:prompt
version: "1.0"
description: >
  This skill should be used when the user says "/spec:prompt", "generate dev prompt",
  "write dev prompt", "implementation prompt", or wants to generate a dev prompt from
  validated requirements and design.
---

# Spec Prompt — Dev Prompt

$ARGUMENTS

You generate and qualify a `specs/{slug}/prompt.md` based on validated requirements and design.

Write all documents in the same language as the user's input.

---

## Phase 1: Load Context

The user provides a slug (e.g., `/spec:prompt my-feature`).

1. Read `specs/{slug}/requirements.md` — verify `Status: Validated`
2. Read `specs/{slug}/design.md` — verify `Status: Validated`
3. If either is missing or not validated, inform the user and suggest the appropriate preceding command

---

## Phase 2: Generate Dev Prompt

Generate `specs/{slug}/prompt.md` using `references/prompt-template.md` as a structural guide.

The prompt must be:
- **Self-contained**: A developer (or Claude in a new session) should be able to implement from this file alone, with references to requirements.md and design.md for deep context
- **Actionable**: Clear implementation tasks in order of execution
- **Constrained**: Include acceptance criteria consolidated from requirements

**Rules:**
- Reference both `requirements.md` and `design.md` in the Context section
- Implementation tasks should be ordered logically (dependencies first)
- Include all Must acceptance criteria as a checklist
- Add any warnings or gotchas discovered during design

Write the file using the `Write` tool.

---

## Phase 3: Qualify Prompt

Present the dev prompt to the user and ask for qualification.

**CRITICAL: You MUST use `AskUserQuestion` here and WAIT for the user's response. Do NOT skip this phase or auto-validate.**

Use `AskUserQuestion`:

```
The dev prompt has been written to specs/{slug}/prompt.md.
```

Options:
1. **"Validate — prompt is ready"** → STOP
2. **"Iterate globally"** → Ask what's wrong, rewrite (back to Phase 2)
3. **"Modify specific sections"** → Collect feedback, update, re-present (stay in Phase 3)

Loop in Phase 3 until the user validates.

---

## Done

When the user validates, output:

```
Spec complete! Files generated:
- specs/{slug}/requirements.md (validated)
- specs/{slug}/design.md (validated)
- specs/{slug}/prompt.md (ready for dev)

To start development, open a new session and provide specs/{slug}/prompt.md as context.
```

**STOP here.**
