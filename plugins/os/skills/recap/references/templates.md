# Templates de fichiers recap

## Template journalier (`{YYYY-MM-DD}.md`)

```markdown
# {Jour_FR} {DD} {mois} {YYYY}

## Chiffres

| Metric | Valeur |
|--------|--------|
| Commits | {N} |
| PRs créées | {N} |
| PRs mergées | {N} |
| PRs reviewées | {N} |
| Claude Code coût | ~${cost} |
| Claude Code modèles | {models} |

## Tickets Linear

| Ticket | Titre | Projet | Statut |
|--------|-------|--------|--------|

## Sessions Claude Code

| Projet | Sessions | Sujets |
|--------|----------|--------|

## PRs créées

| PR | Titre | Repo | Statut |
|----|-------|------|--------|
| [#NN](URL) | titre | repo | mergée/open/fermée |

## PRs reviewées

| PR | Titre | Repo |
|----|-------|------|
| [#NN](URL) | titre | repo |

## Meetings

- {liste}

## Notion

- {pages Notion modifiées/créées}
```

Si aucune PR créée ce jour-là, écrire dans la section PRs créées : `Pas de PR créée. {description courte de la journée}.`

Si aucune donnée pour une section (pas de meeting, pas de Notion), écrire `_Aucun._` ou omettre la section.

---

## Template squelette hebdomadaire (`semaine-{WW}.md` — créé par un récap jour)

```markdown
# Semaine {WW} — {DD_lundi} au {DD_dimanche} {mois} {YYYY}

## Chiffres

| Metric | Valeur |
|--------|--------|
| PRs créées | 0 |
| PRs mergées | 0 |
| PRs reviewées | 0 |
| Commits | 0 |
| Repos touchés | 0 |
| Claude Code coût | ~$0.00 |

### Claude Code usage

| Date | Coût | Modèles |
|------|------|---------|

## Thème principal

_(à compléter lors du wrap-up semaine)_

## Jours

| Jour | PRs créées | PRs mergées | Reviews | Coût CC | Thème |
|------|-----------|-------------|---------|---------|-------|

## Tickets Linear

| Ticket | Titre | Projet | Statut |
|--------|-------|--------|--------|

## Faits marquants

_(à compléter lors du wrap-up semaine)_
```

---

## Template hebdomadaire complet (`semaine-{WW}.md` — écrit lors du wrap-up semaine)

```markdown
# Semaine {WW} — {DD_lundi} au {DD_dimanche} {mois} {YYYY}

## Chiffres

| Metric | Valeur |
|--------|--------|
| PRs créées | {N} |
| PRs mergées | {N} |
| PRs reviewées | {N} |
| Commits | {N} |
| Repos touchés | {N} ({liste}) |
| Claude Code coût | ~${total} |

### Claude Code usage

| Date | Coût | Modèles |
|------|------|---------|
| MM-DD (jour) | $XX.XX | modèles |

## Thème principal : {Titre du thème}

{Synthèse 1-2 phrases}

## Jours

| Jour | PRs créées | PRs mergées | Reviews | Coût CC | Thème |
|------|-----------|-------------|---------|---------|-------|
| [{Jour_FR} {DD}]({YYYY-MM-DD}.md) | {N} | {N} | {N} | ${cost} | {thème court} |

## Tickets Linear

| Ticket | Titre | Projet | Statut |
|--------|-------|--------|--------|

## Faits marquants

### {Projet/thème}
- **Sujet** : description
```

---

## Template mensuel (`mensuel.md`)

```markdown
# Récap Mensuel — {Mois} {YYYY}

## Vue d'ensemble

| Metric | Valeur |
|--------|--------|
| Commits GitHub | **{N}** |
| PRs créées | **{N}** |
| PRs mergées | **{N}** |
| PRs reviewées (autres devs) | **{N}** |
| Tickets Linear traités | **{N}** ({breakdown par statut}) |
| Repos touchés | **{N}** |
| Claude Code coût total | **${total}** |

## Claude Code usage par semaine

| Semaine | Coût | Jour le plus intense | Modèle principal |
|---------|------|----------------------|------------------|
| S{WW} ({plage}) | ${coût} | {date} (${max}) | {modèle} |

## Projets principaux

### 1. {Projet}

{Description 1-2 phrases}

**PRs** : {N} créées, {N} mergées.

## Reviews effectuées ({N} PRs)

Repos : {liste}

Sujets : {liste}

## Timeline

S{WW}  {dates}  {description}

## Fichiers détail

- [Semaine {WW}](semaine-{WW}.md)
```

---

## Formats de lignes pour mises à jour incrémentales

### Ligne "Jours" dans le fichier hebdomadaire

```
| [{Jour_FR} {DD}]({YYYY-MM-DD}.md) | {N} | {N} | {N} | ${cost} | {thème court} |
```

### Ligne "Claude Code usage" dans le fichier hebdomadaire

```
| MM-DD (jour_abrégé) | $XX.XX | modèle1, modèle2 |
```
