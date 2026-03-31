# Commit conventionnel + branche tâche/épique + PR GitHub

Tu es en mode **agent** : exécute les étapes toi-même (terminal, pas seulement des instructions).

## Prérequis

- `git` et accès au dépôt distant.
- **GitHub CLI** : `gh` installé et authentifié (`gh auth status`). Si `gh` manque ou n’est pas connecté, dis-le clairement et arrête avant les étapes PR.

## 0) Contexte à clarifier (si absent dans le fil)

Si l’utilisateur n’a pas indiqué :

- **Identifiant tâche / épique** (ex. issue GitHub `#188`, Jira `PROJ-42`, libellé court).
- **Branche cible de fusion** (souvent `main` ou `develop` — demander ou déduire depuis `git remote show origin` / habitudes du repo).

Demande une seule phrase pour combler ce qui manque, puis continue.

## 1) Branche de travail

Objectif : une branche dédiée à la tâche/épique, pas de commit direct sur la branche par défaut si elle est protégée.

1. `git status` et `git branch --show-current`.
2. Nom de branche **prévisible et traçable**, par exemple :
   - `feat/<slug-tâche>-<résumé-kebab>` ou `fix/<slug>-<résumé>`
   - inclure l’id si connu : `feat/issue-188-twig-profiler-paths`
3. Si la branche actuelle ne convient pas : créer et basculer avec `git switch -c <nom>` (ou `git checkout -b`), en évitant d’écraser le travail local (stash si nécessaire, en expliquant pourquoi).

## 2) Commits conventionnels, **un par fonctionnalité**

Format **[Conventional Commits](https://www.conventionalcommits.org/)** :

```text
<type>(<scope>): <description courte>

[corps optionnel : pourquoi / effet de bord]

[footer : Fixes #123, BREAKING CHANGE: ...]
```

Types courants : `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`, `ci`, `build`.

**Séparation par fonctionnalité** :

1. `git status` puis `git diff` (et `git diff --cached` si déjà stagé).
2. Regrouper les fichiers en **lots logiques** (une capacité utilisateur / un correctif / un refactor isolé par commit).
3. Pour **chaque** lot :
   - `git add -p` ou `git add <chemins>` de façon ciblée (pas `git add .` aveugle sauf lot déjà isolé).
   - Message conforme au format ci-dessus ; scope = module ou package du monorepo si pertinent.
4. Si un seul gros diff mélange plusieurs sujets : proposer de découper en plusieurs commits (re-staging par étapes) avant de pousser.

Ne pas inclure de secrets ; respecter les hooks pre-commit du repo s’ils existent.

## 3) Pousser la branche

```bash
git push -u origin HEAD
```

Si le push est rejeté (historique), ne pas forcer (`--force`) sans accord explicite de l’utilisateur.

## 4) Pull Request : créée ou déjà à jour

**Mise à jour** : après un push sur une branche déjà liée à une PR, GitHub met la PR à jour automatiquement — aucune commande obligatoire.

**Création** si aucune PR n’existe pour cette branche :

1. Vérifier : `gh pr view` (depuis la branche courante) ou `gh pr list --head <branche>`.
2. Si aucune PR : `gh pr create` avec :
   - base : branche par défaut du dépôt ou celle indiquée (`--base <branche>`),
   - titre : résumé clair, id tâche/épique si disponible,
   - corps : contexte, liste de changements, lien `Fixes #NN` / `Refs #NN` si issue connue.
   - Tu peux utiliser `--fill` si les métadonnées du repo le permettent, ou `--title` / `--body` explicites.

Si une PR existe déjà : confirmer l’URL (`gh pr view --web` ou afficher le lien) et rappeler que les nouveaux commits sont déjà visibles.

## 5) Synthèse pour l’utilisateur

À la fin, résumer en français :

- branche utilisée ;
- liste des commits créés (première ligne de chaque message) ;
- lien ou numéro de PR ;
- prochaine étape éventuelle (review, CI, merge).
