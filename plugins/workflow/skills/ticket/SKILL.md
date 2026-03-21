---
name: ticket
description: >
  This skill should be used when the user says "prendre un ticket",
  "faire un ticket", "take CONV-XX", "prends CONV-XX", "fais CONV-XX",
  or references a CONV- identifier to work on. Automates the full workflow
  of picking up a Linear ticket.
---

# Take Linear Ticket

Pick up and implement a Linear ticket from the Conversation team.

**Input:** `$ARGUMENTS` (expects a CONV-XX identifier, e.g., `CONV-123`)

## Phase 1: Read the ticket thoroughly

Gather ALL available context from the Linear ticket before doing anything else.

### 1.1 Fetch the ticket with relations

Use `get_issue` with `includeRelations: true` to get the full ticket including blocking/related/duplicate relations.

### 1.2 Extract and analyze images

If the ticket description contains images (screenshots, mockups, diagrams), use `extract_images` to view and analyze them. These are critical for understanding the visual requirements.

### 1.3 Read comments

Use `list_comments` to read all discussion threads on the ticket. Comments often contain clarifications, decisions, and additional context.

### 1.4 Check related issues

If the ticket has related, blocking, or blocked-by issues, fetch each one with `get_issue` to understand the broader context.

### 1.5 Check sub-issues

If the ticket has sub-issues (children), mention them to the user with a brief summary. Do not automatically work on them.

### 1.6 Present a summary

Display a clear summary to the user:
- **Title** and description
- **Key requirements** extracted from description + images + comments
- **Relations** (blocking, blocked by, related)
- **Sub-issues** if any
- Any **ambiguities or questions** identified

## Phase 2: Update Linear

### 2.1 Assign to user and set status

Update the ticket in a single call:
```
update_issue(id: "<issue-id>", assignee: "me", state: "In Progress")
```

## Phase 3: Create Git branch

Create a new branch from `main` with the Linear autolink format:

**Format:** `feature/conv-XX-title-in-english-kebab-case`

**Rules:**
- Always prefix with `feature/` (required for Linear autolink)
- Identifier in lowercase: `conv-XX` (not `CONV-XX`)
- Title translated to English if the ticket is in French
- Kebab-case, max ~50 characters for the title portion
- Remove articles and filler words for brevity

**Examples:**
- CONV-123 "Ajouter un filtre de destinations" -> `feature/conv-123-add-destination-filter`
- CONV-45 "Fix crash on empty search results" -> `feature/conv-45-fix-empty-search-results-crash`

**Commands:**
```bash
git checkout main
git pull origin main
git checkout -b feature/conv-XX-title-slug
```

## Phase 4: Plan the implementation

Use `EnterPlanMode` to enter plan mode. Thoroughly explore the codebase to understand:
- Which files need to be modified
- Existing patterns to follow
- Potential impacts and edge cases

Present a detailed implementation plan for user approval before writing any code.

## Phase 5: End of workflow (after implementation)

Once the implementation is complete, **ask the user** what they want to do next. Do NOT automatically commit, create a PR, or update the ticket status. Propose the options:

1. **Commit** the changes (use the `git:commit` skill)
2. **Create a PR** (use the `git:pr` skill)
3. **Update the ticket status** (e.g., move to "In Review" or "Done")
4. **Continue working** (more changes needed)

Wait for the user's explicit instructions before proceeding with any of these actions.

## Important reminders

- **Commits**: Use the `git:commit` agent/skill. Do NOT reference CONV-XX in commit messages (the PR branch handles the Linear link).
- **PRs**: Use the `git:pr` agent/skill. No need to add Linear links in the PR body (branch autolink handles it).
- **Never skip the plan phase**: Always use EnterPlanMode before coding, regardless of ticket complexity.
- **Images are mandatory**: Always extract and analyze images from the ticket if present.
