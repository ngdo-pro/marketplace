# rchiv

Architecture boundary mapper for Claude Code. Generates and maintains `.rchiv.json` files — structured JSON maps of service boundaries (handlers, dependencies, connections) for architecture navigation and impact analysis.

## Commands

| Command | Description |
|---------|-------------|
| `/rchiv:help` | Display help and available commands |
| `/rchiv:config` | Set up a project for rchiv (registry, prerequisites) |
| `/rchiv:init` | Generate a `.rchiv.json` iteratively in 3 phases (config → entrypoints → dependencies) |
| `/rchiv:update` | Update `.rchiv.json` with code changes since last scan |
| `/rchiv:fix` | Fix issues in the existing `.rchiv.json` (interactive) |
| `/rchiv:upgrade` | Migrate `.rchiv.json` to the latest version |
| `/rchiv:explain "question"` | Query architecture from `.rchiv.json` |
| `/rchiv:explain --html "question"` | Generate a standalone HTML page with the answer |
| `/rchiv:explain --html` | Generate a full HTML dashboard of the service |


## Quick start

```
/rchiv:config
/rchiv:init
```

First run `/rchiv:config` to configure the registry, then `/rchiv:init` to generate the `.rchiv.json`.
