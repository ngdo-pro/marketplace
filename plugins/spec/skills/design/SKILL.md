---
name: spec:design
version: "1.0"
description: >
  This skill should be used when the user says "/spec:design", "generate design",
  "design document", "write design", or wants to generate a design document from
  validated requirements.
---

# Spec Design — Design Document

$ARGUMENTS

You generate and qualify a `specs/{slug}/design.md` based on validated requirements.

Write all documents in the same language as the user's input.

---

## Phase 1: Load Requirements

The user provides a slug (e.g., `/spec:design my-feature`).

1. Read `specs/{slug}/requirements.md`
2. Verify `Status: Validated` — if not validated, inform the user and suggest running `/spec:start` first
3. If a codebase is available, explore relevant areas to ensure the design fits existing patterns

---

## Phase 2: Generate Design

Generate `specs/{slug}/design.md` using `references/design-template.md` as a structural guide.

**Adaptive depth:**
- **Small/simple project** (≤5 REQs, no complex integrations): Architecture overview, key decisions, main flow. Skip interface contracts and detailed data model.
- **Medium project** (6-15 REQs): Architecture, data model, key flows, technical decisions, edge cases.
- **Large/complex project** (>15 REQs or complex integrations): Full design with interface contracts, sequence diagrams, detailed data model, error handling strategy.

**Rules:**
- Every Must requirement from the requirements doc must be addressed in the design
- Reference REQ IDs when describing how a requirement is fulfilled
- Set `Status: Draft` in the header
- Technical decisions table must include rationale

Write the file using the `Write` tool.

---

## Phase 3: Qualify Design

Present the design document to the user and ask for qualification.

**CRITICAL: You MUST use `AskUserQuestion` here and WAIT for the user's response. Do NOT skip this phase or auto-validate.**

Use `AskUserQuestion`:

```
The design document has been written to specs/{slug}/design.md.
```

Options:
1. **"Validate — design is complete"** → Set `Status: Validated`, update the file, and STOP
2. **"Iterate globally"** → Ask what's wrong, rewrite (back to Phase 2)
3. **"Modify specific sections"** → Collect feedback, update, re-present (stay in Phase 3)

Loop in Phase 3 until the user validates.

---

## Done

When the user validates, update `Status: Validated` in the design file and output:

```
Design validated! File: specs/{slug}/design.md

Next step: run /spec:prompt {slug} to generate the dev prompt.
```

**STOP here. Do NOT proceed to prompt generation.**
