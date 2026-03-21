# Résolution registry

Le registry est toujours présent (configuré dans `.rchiv-config.json`).

## Procédure

1. Fetcher `services.json` du registry via `gh api repos/{repo}/contents/services.json --jq '.content' | base64 -d`
2. Pour chaque service dans `connections`, chercher la correspondance dans `services.json` en appliquant le **matching progressif** ci-dessous
3. Stocker le mapping dans `connections[service].registry_name` et `calls[].registry_name`

## Matching progressif

Pour chaque nom local de service, tenter les correspondances dans cet ordre :

**Niveau 1 — Match exact** : le nom local est une clé de `services.json`.
- `member-api` → clé `member-api` ✓

**Niveau 2 — Match par suffixe/préfixe commun** : le nom local est une variante d'une clé du registry (avec ou sans suffixe `-api`, `-service`, `-crm`, etc.).
- `member` → clé `member-api` ✓ (le nom local est le préfixe de la clé)
- `auth` → clé `auth-api` ✓
- `salesforce` → clé `salesforce-crm` ✓

Règles :
- Retirer les suffixes courants (`-api`, `-service`, `-crm`, `-legacy`, `-bo`, `-worker`) du nom local ET de la clé pour comparer les racines
- Si exactement une clé matche après normalisation → utiliser cette clé
- Si plusieurs clés matchent → passer au niveau 3

**Niveau 3 — Match par contenu du repo path** : chercher le nom local dans les valeurs (paths des repos) de `services.json`.
- `berthe` → `"berthe-legacy": "Evaneos/berthe/.rchiv.json"` ✓ (le path du repo contient `berthe`)

**Niveau 4 — Demander au dev** : si aucun match trouvé, présenter les candidats les plus proches :

```
Le service "{service}" n'a pas de correspondance évidente dans le registry.

Candidats possibles :
{top 5 clés les plus proches, triées par similarité}

Toutes les clés disponibles :
{liste complète}

À quel service correspond "{service}" ? (ou "aucun" s'il n'est pas dans le registry)
```

## Grouper les questions

Ne PAS demander un service à la fois. Regrouper TOUS les services non résolus en une seule question :

```
Résolution registry — {N} services à confirmer :

1. "{service_a}" → je propose "{registry_key_a}" (match par préfixe). OK ?
2. "{service_b}" → pas de correspondance trouvée. Quel est le nom dans le registry ?
3. "{service_c}" → je propose "{registry_key_c}" (match par repo path). OK ?

Services du registry disponibles :
{liste complète}
```

Cela évite les allers-retours multiples et permet au dev de valider d'un coup.
