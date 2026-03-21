---
name: spec:start
version: "1.0"
description: >
  This skill should be used when the user says "/spec:start", "/spec", "spec-driven design",
  "write requirements", "feature spec", "start a spec", or wants to begin a structured
  requirements gathering interview for a new feature or project.
---

# Spec Start — Interview & Requirements

$ARGUMENTS

You guide the user through a structured interview to produce a validated `specs/{slug}/requirements.md`.

Write all documents in the same language as the user's input.

---

## Phase 1: Context Gathering (Optional)

Detect if we're inside a git repository:

```bash
git rev-parse --is-inside-work-tree 2>/dev/null
```

**If yes**, explore the codebase to understand existing patterns:
- Read `CLAUDE.md`, `README.md`, `package.json`, `composer.json`, or similar root files
- Use `Glob` and `Grep` to scan for relevant architecture (routes, models, services, etc.)
- Identify the tech stack, conventions, and existing domain concepts

Store the context mentally — it will inform the interview questions.

**If no**, skip to Phase 2.

---

## Phase 2: Interview

Conduct a rigorous interview using `AskUserQuestion`. The goal is to extract enough information to write complete, numbered requirements.

**Approach:**
- Start from first principles — don't assume you understand the goal
- Challenge assumptions and probe for hidden complexity
- Ask about conflicts with existing features (if codebase was explored)
- Explicitly clarify scope: what's in, what's explicitly out for later
- Explore edge cases, error states, permissions
- Ask 3-7 rounds of questions (adapt to complexity)

**Systematically cover:**
- Core problem / why this feature exists
- Target users and their goals
- Happy path and variations
- Edge cases and error handling
- Integration with existing systems
- Data model implications
- UI/UX flow and states (if applicable)
- Constraints and non-negotiables
- Priority of each capability (Must / Should / Could)

**Determine the slug** from the topic discussed. If ambiguous, ask the user.

---

## Phase 3: Generate Requirements

Based on the interview, generate `specs/{slug}/requirements.md`.

Use the template from `references/requirements-template.md` as a structural guide:

Each requirement follows this format:

```markdown
### REQ-001: {Title}
- **Priority**: Must | Should | Could
- **Scope**: In | Out
- **Description**: ...
- **Acceptance criteria**:
  - [ ] Criterion 1
  - [ ] Criterion 2
- **Dependencies**: None | REQ-XXX
- **Risks**: ...
```

**Rules:**
- Number requirements sequentially: REQ-001, REQ-002, ...
- Every Must requirement needs at least 2 acceptance criteria
- Include a Context section explaining the "why"
- Include a Scope section (in/out)
- Set `Status: Draft` in the header
- Create the directory `specs/{slug}/` if it doesn't exist

Write the file using the `Write` tool.

---

## Phase 4: Qualify Requirements

Present the requirements document to the user and ask for qualification.

**CRITICAL: You MUST use `AskUserQuestion` here and WAIT for the user's response. Do NOT skip this phase or auto-validate.**

Use `AskUserQuestion`:

```
The requirements document has been written to specs/{slug}/requirements.md.
```

Options:
1. **"Validate — requirements are complete"** → Set `Status: Validated`, update the file, and STOP
2. **"Iterate globally"** → Ask what's missing or wrong, then rewrite the full document (back to Phase 3)
3. **"Modify specific REQs"** → Ask which REQ(s) to change, collect feedback, update only those requirements, then re-present (stay in Phase 4)

Loop in Phase 4 until the user validates.

---

## Done

When the user validates, update `Status: Validated` in the requirements file and output:

```
Requirements validated! File: specs/{slug}/requirements.md

Next step: run /spec:design {slug} to generate the design document.
```

**STOP here. Do NOT proceed to design generation.**
