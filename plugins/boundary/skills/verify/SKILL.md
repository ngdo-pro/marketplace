---
name: boundary:verify
description: >-
  This skill should be used when the user asks to "verify boundaries",
  "check boundary results", "audit boundary.json", "fix boundary scan",
  "review boundary map", "validate boundary proofs", "boundary audit",
  or wants to validate and correct the output of a previous boundary scan.
  Provides interactive, section-by-section audit of boundary.json with
  proof verification against actual source code.
argument-hint: "[section to verify: http|workers|cli|cron|http_clients|databases|filesystem|messaging]"
allowed-tools:
  - Read
  - Edit
  - Write
  - Grep
  - Glob
  - AskUserQuestion
---

# Boundary Verify

Interactively audit and correct a `boundary.json` file produced by `/boundary:scan`. Walk through results section by section, verify proofs against actual source code, and apply corrections.

## Prerequisites

A `boundary.json` file must exist at the project root. If missing, suggest running `/boundary:scan` first.

## Workflow

### Step 1: Load and Summarize

Read `boundary.json` and present a high-level summary:

```
## boundary.json — {name}

### Entries
- {n} HTTP endpoints
- {n} workers/consumers
- {n} CLI commands
- {n} cron jobs

### Outputs
- {n} external HTTP calls
- {n} database tables
- {n} filesystem operations
- {n} messaging publications

Which section to verify?
```

If the user provided a section argument, jump directly to that section.

### Step 2: Section-by-Section Audit

For the selected section, present items in a readable format. For each item:

1. **Show the boundary** — method, path, handler, table name, etc.
2. **Show the proof** — file:line reference
3. **Verify the proof** — Read the actual source file at the indicated line to confirm the boundary exists

Present items in batches of 5-10 for readability.

After each batch, ask:
- "Items look correct?" → move to next batch
- "Remove items X, Y" → mark for removal
- "Fix item Z: ..." → apply correction
- "Add missing: ..." → add new item

### Step 3: Spot-Check Proofs

For critical sections (HTTP routes, databases), verify a sample of proofs:

1. Pick 3-5 random items from the section
2. Read the source file at the proof line
3. Confirm the boundary matches what the code says
4. Report any mismatches

### Step 4: Detect Missing Boundaries

For each section, perform complementary grep searches to catch items the AST scripts may have missed. Use the grep patterns documented in **`references/verify-workflow.md`** (section "Complementary Grep Patterns"). Compare grep results against boundary.json items and report any candidates not already captured.

### Step 5: Apply Corrections

After all corrections are collected:

1. Read the current `boundary.json`
2. Apply all additions, removals, and modifications
3. Update the `generated_at` timestamp
4. Recalculate the `stats` section
5. Write the corrected file

Present a diff summary:
```
## Corrections Applied
- Removed: 2 false positive HTTP routes
- Added: 1 missing worker
- Fixed: 3 proof line numbers
- Updated stats
```

### Step 6: Next Section

Ask if the user wants to verify another section or if the audit is complete.

## Verification Patterns

For section-specific verification checklists (what to check for HTTP routes, databases, messaging, etc.) and correction examples, refer to **`references/verify-workflow.md`**.

## Language

All user-facing output MUST match the user's language.
