---
title: Architecture documentée
weight: 45
bookCollapseSection: true
---

Le dépôt contient une **documentation d’architecture** versionnée avec le code, sous `documentation/`. Elle complète les [guides pratiques]({{< relref "/docs/workflows-et-activites/" >}}) par des **décisions**, des **accords d’équipe** et des **pistes** d’évolution.

## À quoi servent ces documents ?

| Type | Rôle |
|------|------|
| **ADR** (*Architecture Decision Records*) | Décisions techniques **acceptées**, contexte, alternatives et conséquences. |
| **WA** (*Working Agreements*) | Conventions de travail : branches, revues, processus. |
| **OST** (*Opportunity Solution Trees* — explorations) | Pistes futures, parité Temporal, ergonomie d’API, etc. |
| **PRD** (*Product Requirements Documents*) | État du produit, scénarios, CI, recettes — ce qui est **livré** ou visé. |

Les fichiers sources sont au format Markdown dans le dépôt GitHub. Les pages **ADR / WA / OST / PRD** ci‑dessous sont **générées** à partir des tableaux de `documentation/INDEX.md` (`python3 hugo-docs/scripts/sync_architecture_from_index.py` à la racine du dépôt). Elles **pointent vers la version sur `main`** sur GitHub.

## Index et dossier complet

- {{< ghdoc "INDEX.md" "Index général (table des matières)" >}}
- [Parcourir le dossier `documentation/` sur GitHub](https://github.com/gplanchat/durable-dev/tree/main/documentation)

## Sous-sections

| Section | Contenu |
|---------|---------|
| [ADR — décisions d’architecture]({{< relref "adr" >}}) | ADR001 à ADR017 |
| [WA — conventions d’équipe]({{< relref "wa" >}}) | WA001 |
| [OST — explorations]({{< relref "ost" >}}) | OST001 à OST004 |
| [PRD — exigences produit]({{< relref "prd" >}}) | PRD001 à PRD005 |

## Autres références (dépôt)

| Document | Description |
|----------|-------------|
| {{< ghdoc "LIFECYCLE.md" "LIFECYCLE.md" >}} | Cycle de vie des documents |
| {{< ghdoc "plans/PLAN001-lib-decouple-messenger.md" "PLAN001" >}} | Découpler Messenger de la lib (statut dans le fichier) |
| {{< ghdoc "audit/AUDIT001-phase2-code-review.md" "AUDIT001" >}} | Revue de code phase 2 |

Des liens vers l’architecture **Hive** et **Runtime** (hors ce monorepo) figurent dans l’{{< ghdoc "INDEX.md" "index source" >}}.
