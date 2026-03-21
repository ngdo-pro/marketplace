# os

Plugin de productivite personnelle pour Claude Code. Gere des idees, recaps d'activite et todos dans un vault Obsidian (`~/vault/OS/`).

## Skills

| Skill | Trigger | Description |
|-------|---------|-------------|
| `os:idea` | `/os:idea`, "nouvelle idee", "brainstorm" | Capturer et iterer sur des idees |
| `os:recap` | `/os:recap`, "recap du jour", "wrap-up semaine" | Generer des recaps d'activite (GitHub, Linear, Claude Code, Notion, ccusage) |
| `os:todo` | `/os:todo`, "ajouter un todo", "todo done" | Gerer des todos en langage naturel |

## Prerequisites

- Vault Obsidian a `~/vault/OS/`
- `gh` CLI (pour les recaps GitHub)
- MCP Linear (pour les tickets)
- MCP Notion (pour les meetings et pages)
- `npx ccusage` (pour les couts Claude Code)
- Skill `git:commit` (pour l'auto-commit des todos et idees)

## Usage

```
/os:idea                    # Capturer une nouvelle idee
/os:idea iterate slug       # Iterer sur une idee existante
/os:recap                   # Recap du jour
/os:recap semaine           # Wrap-up de la semaine
/os:todo ajouter un todo    # Creer un todo en langage naturel
/os:todo list               # Lister les todos
/os:todo done 3             # Marquer le todo #3 comme fait
```
