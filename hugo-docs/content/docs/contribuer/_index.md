---
title: Contribuer
weight: 50
---

Merci de votre intérêt pour **Durable**. Ce guide résume les gestes utiles pour proposer du code ou de la documentation sans surprise.

## Avant de coder

1. **Parcourir** les [guides]({{< relref "/docs/workflows-et-activites/" >}}) et, si besoin, les [ADR]({{< relref "/docs/architecture/adr/" >}}) pertinents (Messenger, replay, tests…).
2. **Lire** le working agreement {{< ghdoc "wa/WA001-conventions-and-reviews.md" "WA001 — conventions et revues" >}} (branches, commits, revues).
3. Une décision technique **nouvelle** ou structurante suit en général un **ADR** — voir {{< ghdoc "adr/ADR001-adr-management-process.md" "ADR001" >}}.

## Environnement local

```bash
git clone --recurse-submodules https://github.com/gplanchat/durable-dev.git
cd durable-dev
# Si le sous-module thème Hugo manque :
git submodule update --init --recursive
```

- **PHP** 8.2+, **Composer** pour les dépendances PHP.
- Application d’exemple : répertoire `symfony/` (`composer install`, `durable:schema:init`, etc.).
- **Site de doc** : répertoire `hugo-docs/` (`hugo server --buildDrafts`).

## Qualité attendue

| Sujet | Référence |
|-------|-----------|
| Style de code | {{< ghdoc "adr/ADR002-coding-standards.md" "ADR002" >}} |
| Tests PHPUnit | {{< ghdoc "adr/ADR003-phpunit-testing-standards.md" "ADR003" >}} |
| CI (idée) | {{< ghdoc "prd/PRD004-ci-github-actions.md" "PRD004" >}} |

Lancer les tests depuis la racine du monorepo (voir `composer.json`) :

```bash
composer test
composer cs:check
```

## Pull requests

- Une PR **ciblée** (une intention par PR) est plus simple à relire.
- Les changements **significatifs** ou les nouvelles dépendances méritent une **discussion** ou un **ADR** en amont.
- Mettez à jour la **documentation** (Markdown dans `documentation/` et/ou pages sous `hugo-docs/content/docs/`) quand le comportement utilisateur change.

## Documentation Hugo

- Pages du site : `hugo-docs/content/docs/`.
- Les sources d’architecture « officielles » restent sous `documentation/` ; les pages Hugo [Architecture]({{< relref "/docs/architecture/" >}}) **lien** vers GitHub pour éviter la duplication.
- Après modification des **tableaux** dans `documentation/INDEX.md`, régénérez les pages ADR/WA/OST/PRD : `python3 hugo-docs/scripts/sync_architecture_from_index.py` (également exécuté en CI avant le build Hugo).

## Où poser des questions ?

Utilisez les **issues** du dépôt [durable-dev](https://github.com/gplanchat/durable-dev/issues) pour signaler un bug, proposer une évolution ou demander une précision.

[← Retour à l’introduction]({{< relref "/docs/" >}})
