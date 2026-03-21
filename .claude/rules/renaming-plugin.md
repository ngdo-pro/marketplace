# Renaming a plugin

This is a local marketplace — plugins are frequently renamed or restructured before being moved to a public marketplace. When renaming a plugin (e.g. `old` → `new`), update **all** of the following:

## In the repo
1. **Directory**: `mv plugins/old plugins/new`
2. **Skill directories**: rename `skills/old-skill/` → `skills/new-skill/` if the skill name changes
3. **Command files**: rename `commands/old-cmd.md` → `commands/new-cmd.md` if the command name changes
4. **`plugins/new/.claude-plugin/plugin.json`**: update `name`
5. **`.claude-plugin/marketplace.json`**: update `name` and `source`
6. **Skill `SKILL.md` frontmatter**: update `name` (e.g. `old:skill` → `new:skill`) and description triggers
7. **Command `.md` files**: update skill reference in body
8. **`README.md`**: update title and command references
9. **`CLAUDE.md`**: update the Plugins table

## In the user's Claude config (`~/.claude/`)
10. **`settings.json`**: rename the plugin key (e.g. `old@personal` → `new@personal`)
11. **`plugins/installed_plugins.json`**: rename the key and update `installPath`
12. **`plugins/cache/personal/`**: replace the old cache directory with the new plugin source:
    ```
    rm -rf ~/.claude/plugins/cache/personal/old
    cp -r plugins/new ~/.claude/plugins/cache/personal/new/0.1.0
    ```

## After renaming
- Restart Claude Code for changes to take effect
- Verify with `grep -r "old-name" ~/.claude/` (ignoring `history.jsonl` and session logs)
