# Workflow Orchestrator — Guide d'Orchestration Multi-Agents

Ce document définit les règles d'orchestration entre les agents du projet Hive pour une automatisation complète des tâches de développement.

---

## Principes Fondamentaux (Karpathy)

**OBLIGATOIRE** : Tous les agents doivent appliquer les 4 principes définis dans [KARPATHY_PRINCIPLES.md](KARPATHY_PRINCIPLES.md).

| # | Principe | Application |
|---|----------|-------------|
| 1 | **Réfléchir Avant de Coder** | Clarifier les hypothèses AVANT d'implémenter |
| 2 | **Simplicité d'Abord** | Code minimum, pas de sur-ingénierie |
| 3 | **Changements Chirurgicaux** | Chaque ligne modifiée doit être traçable à la demande |
| 4 | **Exécution Orientée Objectifs** | Tests first, critères de succès vérifiables |

### Checklist avant implémentation

```markdown
- [ ] Hypothèses explicites ?
- [ ] Plusieurs interprétations possibles ? → Les présenter
- [ ] Approche plus simple existe ? → La proposer
- [ ] Critères de succès définis ? → Tests à écrire
```

---

## Gestion des Tickets GitHub

Les EPICs du projet sont gérés via **GitHub Issues** (label `epic`) avec leur documentation dans `documentation/epics/`.

Chaque EPIC correspond à un dossier `documentation/epics/EPIC-XXX-nom/` contenant le README, les prompts et les spécifications.

### Hiérarchie des Tickets Agile

| Type | Icône | Description | Durée typique |
|------|-------|-------------|---------------|
| **Epic** | 🎯 | Grande initiative découpée en plusieurs US | Multi-sprints |
| **User Story (US)** | 📖 | Fonctionnalité utilisateur (format "En tant que...") | 1-5 jours |
| **Task** | ✅ | Travail technique sans valeur utilisateur directe | < 1 jour |
| **Sub-task** | 📌 | Découpage d'une Task ou US | Quelques heures |
| **Spike** | 💡 | Recherche/investigation time-boxée | 1-2 jours |
| **Bug** | 🐛 | Correction d'un comportement incorrect | Variable |
| **Enabler** | 🔧 | Dette technique, amélioration infra | Variable |
| **Chore** | 🧹 | Maintenance, mise à jour dépendances | < 1 jour |

### Structure des EPICs GitHub

```
GitHub Issues (label: epic)              Documentation
─────────────────────────────────       ──────────────────────────────────────────
#15  [EPIC] Système Datagrid         →  documentation/epics/EPIC-015-chakra-datagrid/
#76  [EPIC] Consolidation FinOps     →  documentation/epics/EPIC-076-accounting-finops-consolidation/
#77  [EPIC] Crédit et Seuils         →  documentation/epics/EPIC-077-accounting-finops-threshold/
#78  [EPIC] FinOps OVHCloud          →  documentation/epics/EPIC-078-cloud-finops-ovh/
#79  [EPIC] Supervision Régions      →  documentation/epics/EPIC-079-cloud-management-supervision/
#80  [EPIC] Suivi Services           →  documentation/epics/EPIC-080-cloud-platform-implementation/
#81  [EPIC] Compilation OCI          →  documentation/epics/EPIC-081-cloud-runtime-compilation/
#82  [EPIC] Cloud Runtime            →  documentation/epics/EPIC-082-cloud-runtime-implementation/
#83  [EPIC] Réconciliation           →  documentation/epics/EPIC-083-cloud-runtime-reconciliation/
#84  [EPIC] Data Engineering         →  documentation/epics/EPIC-084-data-engineering/
#85  [EPIC] Déploiement              →  documentation/epics/EPIC-085-deployment-architecture/
#86  [EPIC] Sales Manager            →  documentation/epics/EPIC-086-sales-manager/
```

### Labels GitHub

| Label | Couleur | Usage |
|-------|---------|-------|
| `type:epic` | 🟣 Violet | Epic |
| `type:story` | 🟢 Vert | User Story |
| `type:task` | 🔵 Bleu | Task |
| `type:spike` | 🟡 Jaune | Spike |
| `type:bug` | 🔴 Rouge | Bug |
| `type:enabler` | 🟠 Orange | Enabler |
| `type:chore` | ⚪ Gris | Chore |
| `priority:high` | Rouge | Urgent |
| `priority:medium` | Jaune | Normal |
| `priority:low` | Vert | Peut attendre |
| `status:blocked` | Noir | Bloqué |

### Utilisation MCP GitHub

```typescript
// Créer une Epic
CallMcpTool({
  server: "user-github",
  toolName: "issue_write",
  arguments: {
    method: "create",
    owner: "gyroscops",
    repo: "hive",
    title: "[Epic] Consolidation FinOps Accounting",
    body: "## Objectif\n...\n## User Stories\n- [ ] US-1\n- [ ] US-2",
    labels: ["type:epic", "domain:accounting"]
  }
});

// Créer une User Story liée à l'Epic
CallMcpTool({
  server: "user-github",
  toolName: "issue_write",
  arguments: {
    method: "create",
    owner: "gyroscops",
    repo: "hive",
    title: "[US] Calcul de la consommation par workspace",
    body: "**En tant que** responsable financier...",
    labels: ["type:story", "domain:accounting"]
  }
});

// Lier comme sub-issue
CallMcpTool({
  server: "user-github",
  toolName: "sub_issue_write",
  arguments: {
    method: "add",
    owner: "gyroscops",
    repo: "hive",
    issue_number: 100,  // Epic (numéro)
    sub_issue_id: 101   // US (ID, pas le numéro!)
  }
});
```

### Règles de liaison OBLIGATOIRES

**IMPORTANT** : Toutes les issues doivent être correctement liées entre elles.

| Relation | Méthode | Obligatoire |
|----------|---------|-------------|
| Epic → US | `sub_issue_write` | ✅ OUI |
| US → Task | `sub_issue_write` | ✅ OUI |
| Task → Sub-task | `sub_issue_write` | ✅ OUI |
| PR → Issue | `Closes #XX` dans body | ✅ OUI |
| Issue → Issue | Référence `#XX` dans body | Recommandé |

### Distinction ID vs Number

```typescript
// Quand on crée une issue, on reçoit :
{
  "id": 3870590586,      // ← ID unique (pour sub_issue_write)
  "number": 16,          // ← Numéro affiché (#16)
  "url": "https://..."
}

// Pour sub_issue_write :
// - issue_number : le NUMÉRO de l'issue parent (#15)
// - sub_issue_id : l'ID de la sub-issue (3870590586)
```

### Workflow de liaison complet

```
1. Créer Epic → récupérer epic_number et epic_id
2. Créer US → récupérer us_number et us_id
3. Lier US à Epic : sub_issue_write(epic_number, us_id)
4. Créer Task → récupérer task_number et task_id
5. Lier Task à US : sub_issue_write(us_number, task_id)
6. Créer PR avec "Closes #task_number" dans body
```

### Exemple de hiérarchie correcte

```
[EPIC] #15 - Système Datagrid
├── [US] #16 - useListController (sub-issue de #15)
│   ├── [TASK] #25 - Tests unitaires (sub-issue de #16)
│   │   └── PR #30 "Closes #25"
│   └── [TASK] #26 - Implémentation (sub-issue de #16)
│       └── PR #31 "Closes #26"
├── [US] #17 - Composants Atoms (sub-issue de #15)
│   └── PR #32 "Closes #17"
└── [US] #18 - Datagrid AG Grid (sub-issue de #15)
    └── PR #33 "Closes #18"
```

---

## Gitflow et Branches

### Convention de nommage des branches

```
<type>/<ticket-number>-<short-description>
```

| Type | Usage | Exemple |
|------|-------|---------|
| `feature/` | Nouvelle fonctionnalité | `feature/42-user-authentication` |
| `bugfix/` | Correction de bug | `bugfix/63-login-race-condition` |
| `hotfix/` | Correction urgente prod | `hotfix/85-critical-security-fix` |
| `release/` | Préparation release | `release/1.2.0` |
| `spike/` | Investigation | `spike/77-evaluate-pdf-libraries` |

### Workflow de branche

```
main ─────────────────────────────────────────────────
  │                                        ▲
  │        release/1.2.0 ──────────────────┤
  │           ▲                            │
  │           │                            │
develop ──────┼────────────────────────────┼──────────
  │           │        ▲         ▲         │
  │           │        │         │         │
  │   feature/42-auth ─┘         │         │
  │                              │         │
  │              bugfix/63-login─┘         │
  │                                        │
  │                          hotfix/85-sec─┘
```

### Commandes Git

```bash
# Créer une branche feature
git checkout develop
git pull origin develop
git checkout -b feature/42-user-authentication

# Créer une PR
gh pr create --title "feat(auth): implement user authentication #42" \
  --body "Closes #42\n\n## Changes\n..." \
  --base develop

# Merge après validation
gh pr merge --squash --delete-branch
```

---

## Conventional Commits

Tous les commits doivent suivre la spécification [Conventional Commits](https://www.conventionalcommits.org/).

### Format

```
<type>(<scope>): <description> [#<ticket>]

[optional body]

[optional footer(s)]
```

### Types

| Type | Description | SemVer |
|------|-------------|--------|
| `feat` | Nouvelle fonctionnalité | MINOR |
| `fix` | Correction de bug | PATCH |
| `docs` | Documentation uniquement | - |
| `style` | Formatage (pas de changement de code) | - |
| `refactor` | Refactoring (pas de nouvelle feature ni fix) | - |
| `perf` | Amélioration de performance | PATCH |
| `test` | Ajout ou correction de tests | - |
| `build` | Changements build system | - |
| `ci` | Changements CI | - |
| `chore` | Maintenance | - |

### Scopes recommandés

| Scope | Domaine |
|-------|---------|
| `accounting` | Bounded context Accounting |
| `auth` | Bounded context Authentication |
| `cloud-mgmt` | Bounded context CloudManagement |
| `cloud-platform` | Bounded context CloudPlatform |
| `cloud-runtime` | Bounded context CloudRuntime |
| `genai` | Bounded context GenAI |
| `platform` | Bounded context Platform |
| `pwa` | Frontend PWA |
| `api` | Backend API |
| `helm` | Charts Helm |
| `ci` | GitHub Actions |
| `deps` | Dépendances |

### Exemples

```bash
feat(cloud-runtime): add environment CRUD operations #42

Implement full CRUD for Environment entity with:
- CreateEnvironmentCommand
- DeleteEnvironmentCommand
- EnvironmentQuery

Closes #42

---

fix(auth): resolve token refresh race condition #63

The concurrent refresh requests were causing session invalidation.
Added mutex lock on refresh operation.

Fixes #63

---

chore(deps): upgrade symfony to 7.2

BREAKING CHANGE: Requires PHP 8.3+
```

---

## Conventional Comments

Tous les commentaires (tickets, PR, revues) doivent suivre [Conventional Comments](https://conventionalcomments.org/).

### Format

```
<label> [decorations]: <subject>

[discussion]
```

### Labels

| Label | Usage | Bloquant par défaut |
|-------|-------|---------------------|
| `praise:` | Compliment sincère | Non |
| `nitpick:` | Préférence mineure | Non |
| `suggestion:` | Proposition d'amélioration | Non |
| `issue:` | Problème identifié | Oui |
| `todo:` | Action requise triviale | Oui |
| `question:` | Demande de clarification | Non |
| `thought:` | Idée à considérer | Non |
| `chore:` | Tâche de process | Oui |
| `note:` | Information importante | Non |

### Décorateurs

| Décorateur | Signification |
|------------|---------------|
| `(blocking)` | Doit être résolu avant merge |
| `(non-blocking)` | Peut être résolu plus tard |
| `(if-minor)` | Résoudre si les changements sont mineurs |
| `(security)` | Concerne la sécurité |
| `(performance)` | Concerne la performance |
| `(ux)` | Concerne l'expérience utilisateur |

### Exemples

```markdown
praise: Excellente utilisation du pattern Repository !

---

issue (blocking): Violation HIVE027 - utilisation de createMock().

Le projet interdit les mocks PHPUnit. Utilisez un InMemory repository.

```php
// ❌ Actuel
$mock = $this->createMock(EnvironmentRepository::class);

// ✅ Correction
$repository = new InMemoryEnvironmentRepository();
```

---

suggestion (non-blocking): Envisager l'extraction dans un service.

Cette logique apparaît 4 fois. Selon HIVE029, on devrait l'extraire.

---

question: Est-ce que ce timeout est suffisant pour les gros fichiers ?

---

todo: Ajouter le test pour le cas où l'environnement n'existe pas.

---

note: Cette PR est liée à l'Epic #100 et ferme les US #101 et #102.
```

---

## Responsabilités des Agents

### Mise à jour des tickets

**Chaque agent est responsable de :**

1. **Mettre à jour** l'état de son ticket quand il commence le travail
2. **Documenter** ses actions et décisions dans le ticket
3. **Signaler** les blocages avec le label `status:blocked`
4. **Fermer** le ticket quand le travail est terminé

### Format de mise à jour de ticket

```markdown
## Mise à jour [Date] - [Agent]

**Status:** En cours / Terminé / Bloqué

### Actions effectuées
- [Action 1]
- [Action 2]

### Fichiers créés/modifiés
- `path/to/file1.php`
- `path/to/file2.ts`

### Conformité ADR
| ADR | Status |
|-----|--------|
| HIVE027 | ✅ |
| HIVE040 | ✅ |

### Prochaines étapes
- [ ] Étape 1
- [ ] Étape 2

### Blocages (si applicable)
**issue (blocking):** [Description du blocage]
```

---

## Méthodologies Agiles Intégrées

Les méthodologies agiles sont supportées par le **MCP Miro** pour créer des boards visuels.

### Configuration MCP Miro

```json
// .cursor/mcp.json
{
  "mcpServers": {
    "user-miro": {
      "url": "https://mcp.miro.com/",
      "transport": "sse"
    }
  }
}
```

**Prérequis** :
- Compte Miro avec accès MCP (Enterprise ou avec MCP activé)
- Authentification OAuth 2.1 lors de la première connexion

### 1. Impact Mapping

```
GOAL → ACTORS → IMPACTS → DELIVERABLES
```

**Board Miro** : Template `impact_mapping`
**Documentation** : `documentation/architecture/IMPACT_MAPPING_SVG_GUIDELINES.md`

### 2. Event Storming

**Éléments** (couleurs sticky notes Miro) :
- 🟧 Domain Events (`#FF9500` orange)
- 🟦 Commands (`#0079BF` bleu)
- 🟨 Actors (`#F2D600` jaune)
- 🟪 Aggregates (`#C377E0` violet)
- 🟩 Policies (`#61BD4F` vert)
- 🟥 Hot Spots (`#EB5A46` rouge)

**Board Miro** : Template `event_storming`
**Documentation** : `documentation/architecture/EVENT_STORMING_*.md`

### 3. Example Mapping

```
🟨 User Story (jaune)
├── 🟦 Règle 1 (bleu)
│   ├── 🟩 Exemple 1a (vert)
│   └── 🟩 Exemple 1b (vert)
└── 🟥 Questions (rouge)
```

**Board Miro** : Template `example_mapping`

### Workflow de création des boards

```
1. product-owner → Créer board Miro (Event Storming/Example Mapping)
2. product-owner → Attacher le lien au ticket GitHub Epic
3. architecte-ddd-hexagonal → Consulter le board, extraire le modèle DDD
4. architecte-api → Consulter le board, concevoir les APIs
5. Tous les agents → Référencer le board dans leurs tickets
```

### Attachement aux tickets GitHub

Chaque board Miro doit être attaché au ticket GitHub correspondant :

```markdown
**note:** Board de conception créé.

## 📋 Miro Board
[Voir le board Event Storming](https://miro.com/app/board/<board_id>)

### Contenu
- X Domain Events
- Y Commands  
- Z Aggregates
```

---

## Matrice d'Assignation ADR → Agents

### HIVE000-019

| ADR | Titre | Agent(s) |
|-----|-------|----------|
| HIVE000 | ADR Management Process | **directeur-projet** |
| HIVE001 | Coding Standards | **analyste-statique**, dev-backend-php |
| HIVE002 | Models | **architecte-ddd-hexagonal** |
| HIVE003 | Dates Management | **dev-backend-php** |
| HIVE004 | Opaque and Secret Data | **auditeur-securite**, dev-backend-php |
| HIVE005 | Common Identifier Model Interfaces | **architecte-ddd-hexagonal** |
| HIVE006 | Query Models for API Platform | **architecte-api** |
| HIVE007 | Command Models for API Platform | **architecte-api** |
| HIVE008 | Event Collaboration | **architecte-ddd-hexagonal** |
| HIVE009 | Message Buses | **dev-backend-php** |
| HIVE010 | Repositories | **architecte-ddd-hexagonal** |
| HIVE011 | In-Memory Repositories | **dev-tests-backend** |
| HIVE012 | Database Repositories | **dev-backend-php** |
| HIVE013 | Collection Management | **architecte-ddd-hexagonal** |
| HIVE014 | ElasticSearch Repositories | **dev-backend-php** |
| HIVE015 | API Repositories | **dev-backend-php** |
| HIVE016 | Database Migrations | **dev-backend-php** |
| HIVE017 | QueryOne Action Class | **architecte-api** |
| HIVE018 | QuerySeveral Action Class | **architecte-api** |
| HIVE019 | Create Action Class | **architecte-api** |

### HIVE020-039

| ADR | Titre | Agent(s) |
|-----|-------|----------|
| HIVE020 | Delete Action Class | **architecte-api** |
| HIVE021 | Replace Action Class | **architecte-api** |
| HIVE022 | Apply Action Class | **architecte-api** |
| HIVE023 | Repository Testing Strategies | **dev-tests-backend**, expert-qa |
| HIVE024 | PHP Enum Naming Conventions | **analyste-statique**, dev-backend-php |
| HIVE025 | Authorization System | **auditeur-securite** |
| HIVE026 | Keycloak Resource and Scope Management | **auditeur-securite** |
| HIVE027 | PHPUnit Testing Standards | **dev-tests-backend**, expert-qa, revue-de-code |
| HIVE028 | Testing Data and Faker Best Practices | **dev-tests-backend** |
| HIVE029 | DRY Principle | **dev-backend-php**, revue-de-code |
| HIVE030 | Test Data Builder Pattern | **dev-tests-backend** |
| HIVE031 | Circuit Breaker Pattern | **ingenieur-performances** |
| HIVE032 | Observability Strategies | **ingenieur-sre-observabilite**, debugger |
| HIVE033 | Hydrator Implementation Patterns | **dev-backend-php** |
| HIVE034 | Service Extraction Pattern | **dev-backend-php** |
| HIVE035 | Database Operation Logging | **debugger**, ingenieur-performances |
| HIVE036 | Input Validation Patterns | **architecte-api** |
| HIVE037 | Pagination Implementation Guidelines | **architecte-api**, ingenieur-performances |
| HIVE038 | Robust Error Handling Patterns | **debugger**, dev-backend-php |
| HIVE039 | Cursor-Based Pagination | **architecte-api**, ingenieur-performances |

### HIVE040-059

| ADR | Titre | Agent(s) |
|-----|-------|----------|
| HIVE040 | Enhanced Models with Property Access | **architecte-ddd-hexagonal** |
| HIVE041 | Cross-Cutting Concerns Architecture | **architecte-ddd-hexagonal**, revue-de-code |
| HIVE042 | Temporal Workflows Implementation | **architecte-kubernetes-helm** |
| HIVE043 | Cloud Resource Sub-Resource Architecture | **architecte-ddd-hexagonal**, architecte-cloud-infrastructure |
| HIVE044 | Kubernetes Resource Labels and Annotations | **architecte-kubernetes-helm** |
| HIVE045 | Public PWA Architecture | **dev-frontend-typescript** |
| HIVE046 | Admin PWA Architecture | **dev-frontend-typescript** |
| HIVE047 | Command-Based API Configuration | **architecte-api** |
| HIVE048 | In-Memory Repository Storage Exceptions | **dev-backend-php** |
| HIVE049 | Amounts and Currency | **architecte-api** |
| HIVE050 | Event Publishing Responsibility | **architecte-ddd-hexagonal**, revue-de-code |
| HIVE051 | RAG Implementation GenAI | **ingenieur-genai-agents** |
| HIVE052 | MCP Server Implementation | **ingenieur-genai-agents** |
| HIVE053 | IDE Bounded Context Prospective Analysis | **ingenieur-genai-agents** |
| HIVE054 | Cloud Resource Graph Architecture | **architecte-ddd-hexagonal**, architecte-cloud-infrastructure |
| HIVE055 | Context Mocking Pattern | **dev-tests-frontend** |
| HIVE056 | JWT Tokens and Claims Architecture | **auditeur-securite** |
| HIVE057 | Side Effect Bus | **dev-backend-php** |
| HIVE058 | Test Pyramid Architecture | **dev-tests-backend**, expert-qa |
| HIVE059 | Test Data Fixtures Management | **dev-tests-backend** |

### HIVE060-064

| ADR | Titre | Agent(s) |
|-----|-------|----------|
| HIVE060 | PDF Generation Accounting | **dev-backend-php** |
| HIVE061 | Jest Testing Standards | **dev-tests-frontend**, expert-qa |
| HIVE062 | Test Data Builder Pattern PWA | **dev-tests-frontend** |
| HIVE063 | Test Data Fixtures Management PWA | **dev-tests-frontend** |
| HIVE064 | Maybe Collection Map Usage | **architecte-ddd-hexagonal** |

---

## Architecture Multi-Agents (27 agents)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           NIVEAU 0 : COORDINATION                            │
│  00-workflow-orchestrator (technique) + 01-directeur-projet (gestion)        │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
┌─────────────────────────────────────────────────────────────────────────────┐
│                      NIVEAU 2 : STRATÉGIE & CONCEPTION                       │
│  02-product-owner │ 03-architecte-ddd │ 04-architecte-api                    │
│  22-designer-ux-ui │ 23-analyste-veille-strategique                          │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
┌─────────────────────────────────────────────────────────────────────────────┐
│                         NIVEAU 3 : IMPLÉMENTATION                            │
│  05-backend │ 06-frontend │ 07-tests-be │ 08-tests-fe │ 09-platform          │
│  10-k8s-helm │ 11-data-etl │ 18-sre │ 19-cloud-infra │ 20-genai │ 21-devops  │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
┌─────────────────────────────────────────────────────────────────────────────┐
│                       NIVEAU 4 : QUALITÉ & VALIDATION                        │
│  12-revue-code │ 13-expert-qa │ 14-analyste-statique                         │
│  15-performances │ 16-securite │ 17-debugger                                 │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
┌─────────────────────────────────────────────────────────────────────────────┐
│                    NIVEAU 5 : DOCUMENTATION & COMMUNICATION                  │
│  24-redacteur-doc-fonctionnelle │ 25-redacteur-doc-technique                 │
│  26-release-manager                                                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Workflows par Type de Tâche

### Workflow Feature

```
PHASE 0 : INITIALISATION
├─ directeur-projet → Créer Epic dans GitHub Project
├─ analyste-veille-strategique → Contexte marché et tendances
├─ product-owner → Event Storming + Example Mapping + User Stories
└─ Créer les sub-issues liées à l'Epic

PHASE 1 : CONCEPTION
├─ designer-ux-ui → Wireframes et parcours utilisateur
├─ architecte-ddd-hexagonal → Architecture domaine
├─ architecte-api → Contrats API
└─ [Si infra] architecte-cloud-infrastructure + architecte-k8s-helm

PHASE 2 : IMPLÉMENTATION (TDD par US)
├─ Créer branche feature/<ticket>-<description>
├─ dev-tests-* → Tests (RED)
├─ dev-* → Code (GREEN)
└─ Commit: feat(<scope>): <description> #<ticket>

PHASE 3 : VALIDATION
├─ analyste-statique → PHPStan + PHP-CS-Fixer
├─ expert-qa → Tests + couverture
├─ revue-de-code → Conformité ADR (Conventional Comments)
├─ auditeur-securite → Sécurité
└─ [Si critique] ingenieur-performances

PHASE 4 : MERGE
├─ PR → develop (Conventional Commits)
├─ Mettre à jour les tickets (Conventional Comments)
└─ ingenieur-devops-cicd → Vérifier CI

PHASE 5 : DOCUMENTATION
├─ redacteur-doc-fonctionnelle → Guides utilisateur, tutoriels
└─ redacteur-doc-technique → API docs, ADR si nécessaire

PHASE 6 : RELEASE (fin de sprint)
├─ release-manager → Changelog, release notes
├─ release-manager → Communication (blog, newsletter)
└─ ingenieur-devops-cicd → Tag et déploiement
```

---

## Auto-Évolution du Workflow

Le **directeur-projet** maintient ce workflow à jour quand :

1. **Nouveau ADR** → Assigner à l'agent pertinent
2. **Nouvel agent** → Ajouter dans la hiérarchie
3. **Nouveau type de ticket** → Documenter dans cette section
4. **Nouvelle méthodologie** → Intégrer dans le workflow

---

## Références

- ADR : `architecture/HIVE*.md`
- Event Storming : `documentation/architecture/EVENT_STORMING_*.md`
- Impact Mapping : `documentation/architecture/IMPACT_MAPPING_*.md`
- Conventional Commits : https://www.conventionalcommits.org/
- Conventional Comments : https://conventionalcomments.org/
- Tickets Agile : Epic, Story, Task, Spike, Bug, Enabler
