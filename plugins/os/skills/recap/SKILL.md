---
name: os:recap
version: "1.0"
description: >
  This skill should be used when the user says "/os:recap", "recap du jour",
  "recap d'aujourd'hui", "recap hier", "wrap-up semaine", "wrap-up mois",
  "wrap-up année", "daily recap", "weekly recap", "monthly recap",
  "yearly recap", "what did I do today", "activity summary",
  or wants to generate activity recaps from GitHub, Linear, Claude Code,
  ccusage, and Notion into the Obsidian vault.
---

# Skill `/os:recap`

Tu es un assistant qui génère des récaps d'activité. Tu agrèges 5 sources (GitHub, Linear, conversations Claude Code, ccusage, Notion) et produis des fichiers markdown dans le vault Obsidian (`~/vault/OS/`).

## Données de référence

- **Vault** : `~/vault/OS/` (hardcodé, accessible depuis n'importe quel projet)
- Jours FR abrégés : dim, lun, mar, mer, jeu, ven, sam
- Mois FR : janvier, février, mars, avril, mai, juin, juillet, août, septembre, octobre, novembre, décembre
- Semaine ISO : le lundi détermine le mois du répertoire

---

## Phase 1 : Parser les arguments et calculer les dates

À partir de `$ARGUMENTS`, détermine l'opération et la plage de dates :

| Argument | Opération | Plage de dates |
|----------|-----------|----------------|
| `aujourd'hui`, `today`, vide | Récap du jour (aujourd'hui) | Aujourd'hui |
| `hier`, `yesterday` | Récap du jour (hier) | Hier |
| Une date ISO `YYYY-MM-DD` | Récap du jour (date donnée) | La date spécifiée |
| `semaine`, `week`, `semaine NN`, `SNN` | Wrap-up semaine | Lundi→Dimanche de la semaine ISO |
| `mois`, `month`, nom de mois FR, `YYYY-MM` | Wrap-up mois | 1er→dernier jour du mois |
| `année`, `year`, `YYYY` | Wrap-up année | 1er jan→31 déc |

**Calculs avec `date` et `python3` :**

```bash
# Numéro de semaine ISO
date -d "$DATE" +%V

# Jour de la semaine (1=lun, 7=dim)
date -d "$DATE" +%u

# Lundi de la semaine contenant $DATE
python3 -c "
from datetime import date, timedelta
d = date.fromisoformat('$DATE')
monday = d - timedelta(days=d.weekday())
print(monday.isoformat())
"

# Dimanche de la semaine
python3 -c "
from datetime import date, timedelta
d = date.fromisoformat('$DATE')
monday = d - timedelta(days=d.weekday())
sunday = monday + timedelta(days=6)
print(sunday.isoformat())
"
```

**Chemin des fichiers cibles :**
- Jour → `~/vault/OS/recaps/{YYYY-MM}/{YYYY-MM-DD}.md` (fichier journalier) + mise à jour `semaine-{WW}.md` (ligne résumé)
- Semaine → `~/vault/OS/recaps/{YYYY-MM}/semaine-{WW}.md` (résumé) + mise à jour `mensuel.md`
- Mois → `~/vault/OS/recaps/{YYYY-MM}/mensuel.md` + mise à jour `~/vault/OS/recaps/{YYYY}.md`
- Année → `~/vault/OS/recaps/{YYYY}.md`

Note : YYYY-MM est basé sur le lundi de la semaine pour le fichier hebdomadaire. Le fichier journalier utilise le YYYY-MM de la date elle-même.

**Cas particulier — semaine à cheval sur 2 mois :** le fichier va dans le mois du lundi.

---

## Phase 2 : Fetcher les données (5 sources, en parallèle)

Lance les 5 sources **en parallèle** via des agents Task. La plage de dates pour les requêtes GitHub est au format `YYYY-MM-DD..YYYY-MM-DD`.

### 2.1 GitHub (`gh api`)

```bash
# Nombre de commits — ATTENTION : requiert le header Accept cloak-preview (utiliser la syntaxe URL)
gh api 'search/commits?q=author:ngdo-pro+committer-date:START_DATE..END_DATE&per_page=1' \
  -H "Accept: application/vnd.github.cloak-preview" --jq '.total_count'

# PRs créées (avec détails) — utiliser la syntaxe URL query (la syntaxe -f ne fonctionne pas toujours)
gh api 'search/issues?q=author:ngdo-pro+type:pr+created:START_DATE..END_DATE&per_page=100' \
  --jq '.items | map({title, number: .number, html_url: .html_url, repo: (.repository_url | split("/") | last), state, created_at, merged_at: .pull_request.merged_at})'

# PRs mergées dans la période (créées avant, mergées dans la plage)
gh api 'search/issues?q=author:ngdo-pro+type:pr+merged:START_DATE..END_DATE&per_page=100' \
  --jq '.items | map({title, number: .number, html_url: .html_url, repo: (.repository_url | split("/") | last), merged_at: .pull_request.merged_at})'

# PRs reviewées (par d'autres auteurs)
gh api 'search/issues?q=reviewed-by:ngdo-pro+-author:ngdo-pro+type:pr+updated:START_DATE..END_DATE&per_page=100' \
  --jq '.items | map({title, number: .number, html_url: .html_url, repo: (.repository_url | split("/") | last)})'
```

**Pagination :** si `total_count > 100`, paginer avec `&page=2`, `&page=3`, etc.

### 2.2 Linear (MCP)

Utilise le tool MCP `mcp__linear-server__list_issues` :
- Filtre : assignee "me", limit 250
- Filtre côté client les issues dont `updatedAt` est dans la plage
- Groupe par projet et statut (Done, In Progress, In Review, etc.)

Si Linear est indisponible, skip la section sans bloquer le récap.

### 2.3 Conversations Claude Code (`~/.claude/projects/`)

Les conversations sont en JSONL dans `~/.claude/projects/{project-name}/{session-uuid}.jsonl`.

**Étapes :**

1. Trouver les sessions de la période :
```bash
find ~/.claude/projects -name "*.jsonl" -newermt "$START_DATE" ! -newermt "$END_DATE_PLUS_1"
```

2. Pour chaque session, extraire :
   - Le nom du projet (du chemin : `-home-ngdo-Evaneos-trip-project` → `trip-project`)
   - Le premier message user (sujet/intention)
   - Le `cwd` et `gitBranch` (corrélation avec les PRs)
   - Le nombre de messages
   - Vérifier si `session-memory/summary.md` existe

```bash
# Extraire les métadonnées
head -50 "$SESSION_FILE" | jq -r 'select(.type == "user") | {timestamp, cwd, gitBranch, content: (.message.content // "" | tostring[0:200])}'
```

3. Grouper par projet et résumer.

### 2.4 Claude Code usage (ccusage)

```bash
npx ccusage --since YYYYMMDD --until YYYYMMDD
```

Parser la sortie pour extraire : coût par jour, modèles utilisés par jour.

Si ccusage échoue, skip sans bloquer.

### 2.5 Notion (MCP)

Utilise `mcp__notion__notion-search` avec le filtre `created_by_user_ids`.

- **Notion user ID** : `392a55b8-0c3e-482f-8be9-b45db95e9150` (`Nicolas Gomes`)
- **OBLIGATOIRE** : toujours passer `filters: { created_by_user_ids: ["392a55b8-0c3e-482f-8be9-b45db95e9150"] }` pour réduire le bruit
- La recherche Notion AI retourne aussi les sources connectées (Google Calendar, Slack, GitHub, Google Drive)
- **Ne garder que** : les meetings (google-calendar) et les pages Notion (page)
- **Exclure** : les résultats Slack (slack) et Google Drive (google-drive) — ce sont des channels/docs où l'utilisateur est membre, pas forcément ses contributions
- Ne garder que les résultats dont le timestamp correspond à la période demandée

**⚠️ ATTENTION — filtre `created_by_user_ids` non fiable** : ce filtre ne garantit pas que les pages retournées ont été créées par l'utilisateur. Des pages d'autres personnes peuvent apparaître (ex : "Weekly Business Review" animé par un autre collègue). Pour les pages Notion (type `page`), appliquer un **filtre manuel par contexte** :
- Garder : notes perso, explorations techniques, retours d'entretien, documents clairement initiés par l'utilisateur
- Exclure : documents d'équipe, reviews de business, slides produit créés par d'autres, focus groups externes

Si Notion est indisponible, skip la section sans bloquer le récap.

---

## Phase 3 : Formater le contenu

### 3.A Récap du jour → fichier journalier + mise à jour résumé hebdomadaire

Logique **idempotent** (relancer remplace le fichier journalier et la ligne du jour dans le résumé hebdo).

`mkdir -p ~/vault/OS/recaps/{YYYY-MM}/` avant d'écrire.

#### Étape 1 : Écrire le fichier journalier `{YYYY-MM-DD}.md`

Utiliser le template journalier depuis `references/templates.md`. Écrire avec l'outil `Write`.

#### Étape 2 : Mettre à jour le fichier hebdomadaire `semaine-{WW}.md`

1. Lire le fichier `semaine-{WW}.md` existant, ou créer le squelette hebdomadaire depuis `references/templates.md`.

2. **Tableau "Jours"** : ajouter/remplacer la ligne du jour (idempotent — matcher sur la date dans le lien). Voir format dans `references/templates.md`.

3. **Claude Code usage** : ajouter/mettre à jour la ligne du jour dans le tableau.

4. **Tickets Linear** : merger par ID de ticket (mettre à jour le statut si déjà présent, ajouter si nouveau).

5. **Chiffres** : recalculer les totaux en additionnant les valeurs de toutes les lignes du tableau "Jours" + repos touchés de toute la semaine.

6. **NE PAS toucher** "Thème principal" ni "Faits marquants" s'ils sont déjà remplis — ces sections sont réservées au wrap-up semaine. Les laisser vides ou avec le placeholder.

### 3.B Wrap-up semaine → résumé avec liens vers fichiers journaliers + mise à jour mensuel

**Re-fetch toute la semaine** (pas incrémental). Réécrire le fichier complet.

1. Lire tous les fichiers journaliers `{YYYY-MM-DD}.md` de la semaine pour agréger les données.
2. Si des fichiers journaliers manquent, fetcher les données et les créer d'abord (comme en 3.A étape 1).
3. Remplir **Thème principal** : synthèse IA des travaux de la semaine (1-2 phrases max).
4. Remplir **Faits marquants** : groupés par projet/thème, bullet points avec **bold** pour les sujets clés. Utiliser les résumés de sessions pour contextualiser les PRs.
5. Lire les `session-memory/summary.md` disponibles pour enrichir le récap.
6. Corréler sessions ↔ PRs via la `gitBranch`.

Le fichier hebdomadaire **ne contient plus le détail des PRs par jour**, mais un résumé avec liens vers les fichiers journaliers. Utiliser le template hebdomadaire complet depuis `references/templates.md`.

Puis mettre à jour `~/vault/OS/recaps/{YYYY-MM}/mensuel.md` :
- Lire `mensuel.md` existant
- Ajouter/mettre à jour la ligne de cette semaine dans le tableau "Claude Code usage par semaine"
- Recalculer les totaux de "Vue d'ensemble"
- Ajouter/mettre à jour la semaine dans "Timeline" et "Fichiers détail"

### 3.C Wrap-up mois → réécriture complète + mise à jour annuel

1. Lire tous les `semaine-{WW}.md` du mois
2. Agréger les métriques (PRs, tickets, coût, sessions)
3. Réécrire `~/vault/OS/recaps/{YYYY-MM}/mensuel.md` complet avec le template mensuel depuis `references/templates.md`.

4. Mettre à jour `~/vault/OS/recaps/{YYYY}.md` (créer si inexistant).

### 3.D Wrap-up année → réécriture complète

1. Lire tous les `mensuel.md` de l'année
2. Agréger en récap annuel avec : Vue d'ensemble, mois par mois, projets principaux, timeline

---

## Phase 4 : Résumé

Afficher un résumé concis :
- Ce qui a été écrit (fichier, nombre de PRs, tickets, sessions, coût)
- Le chemin du fichier créé/mis à jour
- Suggestion de la prochaine action :
  - Après un récap jour → suggérer `/os:recap semaine` quand la semaine est terminée
  - Après un wrap-up semaine → suggérer `/os:recap mois` quand le mois est terminé
  - Après un wrap-up mois → suggérer `/os:recap année` quand l'année est terminée

---

## Règles importantes

1. **Idempotence** : relancer `/os:recap aujourd'hui` réécrit le fichier journalier et remplace la ligne du jour dans le résumé hebdo (pas de doublon)
2. **Sources indisponibles** : si Notion, Linear ou ccusage échouent, skip la section correspondante, ne pas bloquer le récap
3. **Sessions sans summary** : utiliser le premier message user comme résumé
4. **Pagination GitHub** : si `total_count > 100`, paginer
5. **Corrélation sessions/PRs** : la `gitBranch` des sessions permet de relier une session à une PR
6. **Accents français** : vérifier que tous les accents sont corrects partout
7. **Statuts PR** : utiliser `mergée`, `fermée`, `open` (bold `**open**` pour emphase)
8. **Coûts** : préfixer d'un `~` pour indiquer l'approximation (ex: `~$126.96`)
9. **Parallélisme** : toujours fetcher les 5 sources en parallèle pour la performance
