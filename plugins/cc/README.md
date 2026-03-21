# cc

Claude Code linter — reviews plugins, skills, commands, hooks, agents, CLAUDE.md, and rules against best practices.

## Usage

```
/cc:lint [path]
```

- Without argument: reviews the plugin in the current directory
- With a path: reviews the specified component

## Supported targets

- **Plugin**: directory with `.claude-plugin/plugin.json`
- **Skill**: `SKILL.md` file or `skills/{name}/` directory
- **Command**: `.md` file in `commands/`
- **Hook**: `hooks/hooks.json` or `hooks/` directory
- **Agent**: `.md` file in `agents/`
- **CLAUDE.md**: any `CLAUDE.md` file
- **Rules**: `.claude/rules/` directory or individual rule files

## Components

| Component | Description |
|-----------|-------------|
| `commands/lint.md` | Slash command entry point |
| `skills/lint/SKILL.md` | Review workflow (5 phases) |
| `skills/lint/references/best-practices.md` | Checklist rubric |
| `skills/lint/references/report-template.md` | Report structure |
