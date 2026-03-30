---
title: PRD
weight: 49
---

*Product Requirements Documents* — état du produit, scénarios, CI, recettes. Tableau synchronisé depuis `documentation/INDEX.md`.

| PRD | Titre | Description |
|-----|------------|-------------|
| {{< ghdoc "prd/PRD001-current-component-state.md" "PRD001" >}} | Current component state | Workflows, activities, event store, transports |
| {{< ghdoc "prd/PRD002-in-flight-workflow-scenarios.md" "PRD002" >}} | In-flight workflow scenarios (distributed) | Activity queue, resume, intermediate log |
| {{< ghdoc "prd/PRD003-durable-test-case-base.md" "PRD003" >}} | `DurableTestCase` base | In-memory stack, assertions, dedicated worker teardown |
| {{< ghdoc "prd/PRD004-ci-github-actions.md" "PRD004" >}} | GitHub Actions CI | CS, PHPUnit strict coverage, PCOV report |
| {{< ghdoc "prd/PRD005-symfony-empty-project-recipe.md" "PRD005" >}} | Empty Symfony project recipe (~3 min) | Monorepo quick start, bundle integration, auto-registered handlers |

[← Retour à l’architecture]({{< relref "/docs/architecture/" >}})
