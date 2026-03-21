# Synchronisation registry

Après le rapport final, si un registry est configuré dans `.rchiv.json` (`registry.repo`), proposer la synchronisation.

## Proposition

Demander au dev via `AskUserQuestion` :

> "Le .rchiv.json a été modifié. Veux-tu synchroniser avec le registry ?"

**Si non** → STOP.

**Si oui** → exécuter les étapes ci-dessous.

## Étapes

1. **Identifier le nom canonique** : fetcher `services.json` depuis le registry (`gh api repos/{registry.repo}/contents/services.json --jq '.content' | base64 -d`) et chercher l'entrée dont le repo correspond à `_meta.repo`. Si aucune entrée ne correspond, demander au dev le nom canonique à utiliser.
2. **Cloner le registry** : `git clone git@github.com:{registry.repo}.git /tmp/rchiv-registry-{timestamp}`
3. **Copier le fichier** : copier `.rchiv.json` → `services/{nom_canonique}.rchiv.json` dans le clone
4. **Mettre à jour `services.json`** : si le service n'est pas encore enregistré, ajouter l'entrée (garder les clés triées alphabétiquement)
5. **Créer une branche, commit et PR** :
   - `git checkout -b rchiv/{nom_canonique}-{date}`
   - `git add .` + `git commit -m "chore: update {nom_canonique} boundary map"`
   - `gh pr create --title "chore: update {nom_canonique}" --body "Mise à jour automatique du .rchiv.json"`
6. **Nettoyer** : `rm -rf /tmp/rchiv-registry-{timestamp}`
7. **Afficher l'URL de la PR**
