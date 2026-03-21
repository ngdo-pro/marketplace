---
description: Conduct a detailed interview about a specific feature or topic to gather information and create a specification.
---

# Interview

$ARGUMENTS

## Phase 1: Context gathering

Before asking questions, explore relevant parts of the codebase to understand:
- Related existing features, models, and patterns
- Relevant domain concepts from docs/

## Phase 2: Interview

Use `AskUserQuestionTool` to conduct a free-form interview that starts as brainstorming and progressively converges toward an implementation plan.

**Approach:**
- Start from first principles—don't assume you understand the goal
- Challenge assumptions and probe for hidden complexity
- Ask about conflicts with existing features
- Explicitly clarify scope: what's in, what's explicitly out for later
- Explore edge cases, error states, permissions

**Cover these areas** (not necessarily in order):
- Core problem / why this feature
- Users and their goals
- Happy path and variations
- Edge cases and error handling
- Integration with existing features
- Data model implications
- UI/UX flow and states
- Constraints and non-negotiables

Continue asking questions until you have absolutely everything needed to write a complete implementation plan. Submit your decisions for validation as you go.

## Phase 3: Plan

Write a plan including:

- **Summary**: One paragraph
- **Context**: Background information (why, goals, links to related issues or documentation)
- **Scope**: What's included / explicitly excluded
- **Product requirements**: UI/UX, user stories
- **Technical plan**: Architecture, data model, implementation details
- **TODOs**: Specific tasks to be done
- **Open questions**: Anything still unresolved

The plan must be written in the same language as the user's input (e.g., if the user asked in French, write the plan in French).
