---
name: agent-browser:browse
description: >
  Launch a guided browser session to accomplish a task on the web.
  Examples: /agent-browser:browse "fill out the contact form on example.com",
  /agent-browser:browse "log into my dashboard and download the latest report",
  /agent-browser:browse "scrape product prices from example.com/shop"
argument-hint: <instruction describing what to do on the web>
allowed-tools:
  - Bash
---

# Browse — Guided Browser Session

Execute a web task described by the user in natural language using `agent-browser`.

**Input:** `$ARGUMENTS` — a free-form instruction describing what to accomplish (e.g., "log into example.com and download the latest invoice")

For full command reference and common patterns, consult the `agent-browser:browser-automation` skill.

## Procedure

### 1. Parse the instruction

Analyze `$ARGUMENTS` to identify:
- **Target URL** (if mentioned, or derive from context)
- **Goal** (what to accomplish: fill a form, extract data, navigate, etc.)
- **Steps** needed to achieve the goal

If the instruction is ambiguous or missing a URL, ask the user for clarification before proceeding.

### 2. Open the browser

```bash
agent-browser open <url>
```

On Linux, if launch fails, retry with `--args "--no-sandbox"`.

### 3. Execute the task iteratively

Follow the open / snapshot / interact / verify cycle. Always run `agent-browser snapshot` before interacting — never guess element refs. After each significant action, verify with `agent-browser screenshot` or a new `agent-browser snapshot`. If navigation occurs, wait briefly then re-snapshot.

### 4. Report results

Once the task is complete:
- Take a final `agent-browser screenshot` to confirm the outcome
- Summarize what was accomplished
- Report any issues or unexpected results

### 5. Clean up

```bash
agent-browser close
```

## Guidelines

- **Always snapshot before interacting** — never guess element refs.
- **Verify after critical actions** — screenshot or snapshot to confirm state changes.
- **Handle errors gracefully** — if a click or fill fails, re-snapshot and retry with updated refs.
- **Communicate progress** — inform the user at each major step of what is happening.
- **Respect user language** — respond in the same language as the user's instruction.
