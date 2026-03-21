# workflow

Plugin de workflow de developpement pour Claude Code. Automatise la prise de tickets Linear et les interviews de specs.

## Skills

| Skill | Trigger | Description |
|-------|---------|-------------|
| `ticket` | "prends CONV-XX", "faire un ticket" | Workflow complet : lecture ticket, assign, branche, plan, implementation |
| `interview` | `/interview {sujet}` | Interview structuree pour creer une spec de feature |

## Prerequisites

- MCP Linear (pour les tickets CONV-XX)
- `git` CLI
- Skill `git:commit` et `git:pr` (pour la fin du workflow ticket)
