# Agents Cursor — Projet Hive

Ce dossier contient les définitions des 27 sous-agents Cursor pour l'automatisation du développement du projet Hive.

## Principes Fondamentaux (Karpathy)

Tous les agents DOIVENT suivre les **Principes Karpathy** définis dans [KARPATHY_PRINCIPLES.md](KARPATHY_PRINCIPLES.md) :

| # | Principe | Description |
|---|----------|-------------|
| 1 | **Réfléchir Avant de Coder** | Ne pas supposer, exposer les compromis, demander si incertain |
| 2 | **Simplicité d'Abord** | Code minimum, pas de sur-ingénierie |
| 3 | **Changements Chirurgicaux** | Ne toucher que ce qui est nécessaire |
| 4 | **Exécution Orientée Objectifs** | Définir des critères de succès vérifiables |

## MCP Servers Utilisés

| MCP Server | Usage | Agents |
|------------|-------|--------|
| **user-github** | Tickets, PR, issues | Tous |
| **user-miro** | Boards Event Storming, Wireframes | product-owner, architecte-ddd, designer-ux |

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

## Gestion des Tickets GitHub - Projet Centralisé #10

**IMPORTANT** : Tous les tickets sont gérés dans le projet GitHub centralisé :
- **URL** : https://github.com/users/gplanchat/projects/10
- **Règle** : Voir `.cursor/rules/session-workflow.md` pour le workflow complet

### Hiérarchie Agile

| Type | Préfixe | Label | Description |
|------|---------|-------|-------------|
| **Epic** | `[EPIC]` | `epic` | Grande initiative multi-sprints, référencé EPIC-{numero} |
| **User Story** | `[Story]` | `story` | Fonctionnalité utilisateur |
| **Task** | `[Task]` | `task` | Travail technique |
| **Subtask** | `[Subtask]` | `subtask` | Découpage d'une Task/Story |
| **Spike** | `[Spike]` | `spike` | Recherche time-boxée (max 2 jours) |
| **Bug** | `[Bug]` | `bug` | Correction de dysfonctionnement |
| **Enabler** | `[Enabler]` | `enabler` | Dette technique |
| **Chore** | `[Chore]` | `chore` | Maintenance |

### Workflow Kanban (7 statuts)

| Statut | Description | Responsable |
|--------|-------------|-------------|
| Backlog | En attente de priorisation | Product Owner |
| Ready | Prêt à être travaillé | - |
| In Progress | En cours de développement | Agent/Dev |
| In Review (AI) | PR créée, revue par l'agent | Agent |
| Pending Review | En attente de revue humaine | - |
| **In Review (Human)** | Revue par un humain | **OBLIGATOIRE** |
| Done | Terminé et mergé | - |

### Règles de Revue

- L'agent **DOIT** passer par "In Review (AI)" puis "Pending Review"
- L'agent **NE PEUT PAS** merger une PR
- L'agent **NE PEUT PAS** passer directement en "Done"
- **Seul un humain** peut valider et merger

### Gestion du Projet GitHub V2 — OBLIGATIONS

**CRITIQUE** : Chaque agent qui prend en charge une issue DOIT :
1. **Placer l'issue dans l'itération courante** (sprint actif)
2. **Maintenir le statut à jour** tout au long du travail
3. **Lier les PR aux issues** via la fonctionnalité "Development"

Ces obligations sont **NON NÉGOCIABLES** et permettent un suivi fluide du projet.

### Gestion des Itérations (Sprints)

**OBLIGATION** : Quand un agent prend en charge une issue, il DOIT l'assigner à l'itération courante.

#### Constantes du projet

```bash
PROJECT_ID="PVT_kwHOAAJTL84BNyIQ"              # ID du projet GitHub
PROJECT_NUMBER="10"                             # Numéro du projet
STATUS_FIELD_ID="PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ"  # Champ Status
ITERATION_FIELD_ID="PVTIF_lAHOAAJTL84BNyIQzg8sGKQ" # Champ Iteration
```

#### Récupérer l'itération courante

```bash
# Récupérer les itérations via GraphQL
CURRENT_ITERATION=$(gh api graphql -f query='
query {
  user(login: "gplanchat") {
    projectV2(number: 10) {
      field(name: "Iteration") {
        ... on ProjectV2IterationField {
          configuration {
            iterations {
              id
              title
              startDate
              duration
            }
          }
        }
      }
    }
  }
}' --jq '.data.user.projectV2.field.configuration.iterations | map(select(
  (.startDate | strptime("%Y-%m-%d") | mktime) <= now and 
  ((.startDate | strptime("%Y-%m-%d") | mktime) + (.duration * 86400)) >= now
)) | .[0].id')
```

#### Assigner à l'itération courante

```bash
# Obtenir l'item ID du ticket dans le projet
ITEM_ID=$(gh project item-list $PROJECT_NUMBER --owner gplanchat --format json | \
  python3 -c "import json,sys; data=json.load(sys.stdin); \
  [print(i['id']) for i in data['items'] if i['content'].get('number')==<ISSUE_NUMBER>]")

# Assigner à l'itération courante
gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
  --field-id "$ITERATION_FIELD_ID" --iteration-id "$CURRENT_ITERATION"
```

#### Script complet pour prise en charge d'une issue

```bash
#!/bin/bash
# Script à exécuter quand un agent prend en charge une issue
ISSUE_NUMBER=$1

# 1. Récupérer l'item ID
ITEM_ID=$(gh project item-list 10 --owner gplanchat --format json | \
  python3 -c "import json,sys; data=json.load(sys.stdin); \
  [print(i['id']) for i in data['items'] if i['content'].get('number')==$ISSUE_NUMBER]" 2>/dev/null | head -1)

if [ -z "$ITEM_ID" ]; then
  echo "Issue #$ISSUE_NUMBER non trouvée dans le projet. Ajout en cours..."
  # Ajouter l'issue au projet si elle n'y est pas
  gh project item-add 10 --owner gplanchat --url "https://github.com/gplanchat/hive/issues/$ISSUE_NUMBER"
  ITEM_ID=$(gh project item-list 10 --owner gplanchat --format json | \
    python3 -c "import json,sys; data=json.load(sys.stdin); \
    [print(i['id']) for i in data['items'] if i['content'].get('number')==$ISSUE_NUMBER]" 2>/dev/null | head -1)
fi

# 2. Récupérer l'itération courante
CURRENT_ITERATION=$(gh api graphql -f query='query { user(login: "gplanchat") { projectV2(number: 10) { field(name: "Iteration") { ... on ProjectV2IterationField { configuration { iterations { id title startDate duration } } } } } } }' 2>/dev/null | \
  python3 -c "import json,sys,time; data=json.load(sys.stdin); now=time.time(); \
  iters=data['data']['user']['projectV2']['field']['configuration']['iterations']; \
  [print(i['id']) for i in iters if time.strptime(i['startDate'],'%Y-%m-%d') <= time.gmtime() and time.mktime(time.strptime(i['startDate'],'%Y-%m-%d')) + (i['duration']*86400) >= now]" 2>/dev/null | head -1)

# 3. Assigner à l'itération courante
if [ -n "$CURRENT_ITERATION" ] && [ -n "$ITEM_ID" ]; then
  gh project item-edit --project-id "PVT_kwHOAAJTL84BNyIQ" --id "$ITEM_ID" \
    --field-id "PVTIF_lAHOAAJTL84BNyIQzg8sGKQ" --iteration-id "$CURRENT_ITERATION"
  echo "Issue #$ISSUE_NUMBER assignée à l'itération courante"
fi

# 4. Passer le statut à "In Progress"
gh project item-edit --project-id "PVT_kwHOAAJTL84BNyIQ" --id "$ITEM_ID" \
  --field-id "PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ" --single-select-option-id "47fc9ee4"
echo "Issue #$ISSUE_NUMBER en In Progress"
```

### Statuts de Projet GitHub

**OBLIGATION** : Chaque agent DOIT maintenir le statut des tickets à jour dans le projet GitHub.

| Statut | Option ID | Quand l'utiliser |
|--------|-----------|------------------|
| **Todo** | `f75ad846` | Ticket créé, pas encore démarré |
| **In Progress** | `47fc9ee4` | Travail en cours (code, tests, review) |
| **Requires Feedback** | `56937311` | Attente de précisions, questions posées, conception en évaluation |
| **Done** | `98236657` | Travail terminé, PR mergée ou issue fermée |

#### Commandes gh pour mise à jour des statuts

```bash
# Constantes
PROJECT_ID="PVT_kwHOAAJTL84BNyIQ"
STATUS_FIELD_ID="PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ"

# Trouver l'item ID du ticket dans le projet
ITEM_ID=$(gh project item-list 10 --owner gplanchat --format json | \
  python3 -c "import json,sys; data=json.load(sys.stdin); \
  [print(i['id']) for i in data['items'] if i['content'].get('number')==42]")

# Passer à "In Progress" (option ID: 47fc9ee4)
gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
  --field-id "$STATUS_FIELD_ID" --single-select-option-id "47fc9ee4"

# Passer à "Requires Feedback" (option ID: 56937311)
gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
  --field-id "$STATUS_FIELD_ID" --single-select-option-id "56937311"

# Passer à "Done" (option ID: 98236657)
gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
  --field-id "$STATUS_FIELD_ID" --single-select-option-id "98236657"
```

#### Règles de transition (OBLIGATOIRES)

| Événement | Action sur le statut |
|-----------|---------------------|
| **Prise en charge d'une issue** | → **Itération courante** + **In Progress** |
| Début de travail sur un ticket | Todo → **In Progress** |
| Question posée à l'utilisateur | In Progress → **Requires Feedback** |
| Spike/évaluation en cours | In Progress → **Requires Feedback** |
| Attente de décision d'architecture | In Progress → **Requires Feedback** |
| Réponse reçue, reprise du travail | Requires Feedback → **In Progress** |
| PR créée | Conserver **In Progress** |
| PR en review, attente reviewer | In Progress → **Requires Feedback** (optionnel) |
| PR mergée | In Progress → **Done** |
| Issue fermée | → **Done** |

### Checklist de prise en charge d'une issue (OBLIGATOIRE)

Quand un agent prend en charge une issue, il DOIT effectuer ces actions dans l'ordre :

- [ ] **1. Assigner à l'itération courante** (sprint actif)
- [ ] **2. Passer le statut à "In Progress"**
- [ ] **3. Créer la branche de travail**
- [ ] **4. Maintenir le statut à jour** pendant le travail
- [ ] **5. Lier la PR à l'issue** via "Development" (mots-clés Closes/Part of)
- [ ] **6. Passer en "Done"** quand terminé

### Conventions

| Convention | Standard | Documentation |
|------------|----------|---------------|
| **Commits** | Conventional Commits | https://www.conventionalcommits.org/ |
| **Comments** | Conventional Comments | https://conventionalcomments.org/ |
| **Branches** | Gitflow | `<type>/<ticket>-<description>` |
| **Statuts** | GitHub Project V2 | Voir section ci-dessus |

### Exemple de workflow

```bash
# Créer branche depuis ticket #42
git checkout develop && git pull
git checkout -b feature/42-user-authentication

# Commit conventionnel avec référence ticket
git commit -m "feat(auth): implement user authentication #42"

# PR vers develop (pas main)
gh pr create --base develop --title "feat(auth): implement user authentication #42"

# Mettre le ticket en "In Review (AI)" puis "Pending Review"
# Attendre la revue humaine avant merge
```

## Structure des Agents (27 total)

```
.cursor/agents/
├── README.md                          # Ce fichier
├── workflow-orchestrator.md           # Guide complet d'orchestration
│
│ # NIVEAU 0 : COORDINATION
├── 00-workflow-orchestrator.md        # Orchestration technique des agents  ← NOUVEAU
├── 01-directeur-projet.md             # HIVE000, gestion de projet
│
│ # NIVEAU 2 : STRATÉGIE & CONCEPTION
├── 02-product-owner.md                # Event Storming, Example Mapping, Impact Mapping
├── 03-architecte-ddd-hexagonal.md     # 11 ADR (HIVE002/005/008/010...)
├── 04-architecte-api.md               # 12 ADR (HIVE006/007/017-022...)
├── 22-designer-ux-ui.md               # UX/UI, wireframes, accessibilité  ← NOUVEAU
├── 23-analyste-veille-strategique.md  # Tendances ETL/iPaaS/IA, concurrence ← NOUVEAU
│
│ # NIVEAU 3 : IMPLÉMENTATION
├── 05-dev-backend-php.md              # 14 ADR (HIVE001/003/009/012...)
├── 06-dev-frontend-typescript.md      # 2 ADR (HIVE045/046)
├── 07-dev-tests-backend.md            # 7 ADR (HIVE011/023/027...)
├── 08-dev-tests-frontend.md           # 4 ADR (HIVE055/061/062/063)
├── 09-platform-engineer.md            # Infrastructure Docker
├── 10-architecte-kubernetes-helm.md   # 2 ADR (HIVE042/044)
├── 11-ingenieur-data-etl.md           # ETL/ESB Gyroscops
├── 18-ingenieur-sre-observabilite.md  # HIVE032, monitoring, SLO/SLI  ← NOUVEAU
├── 19-architecte-cloud-infrastructure.md # HIVE043/054, IaC, multi-cloud ← NOUVEAU
├── 20-ingenieur-genai-agents.md       # HIVE051/052/053, RAG, MCP     ← NOUVEAU
├── 21-ingenieur-devops-cicd.md        # CI/CD, GitOps, releases       ← NOUVEAU
│
│ # NIVEAU 4 : QUALITÉ & VALIDATION
├── 12-revue-de-code.md                # TOUS les ADR (validation)
├── 13-expert-qa.md                    # 4 ADR (HIVE023/027/058/061)
├── 14-analyste-statique.md            # 2 ADR (HIVE001/024)
├── 15-ingenieur-performances.md       # 4 ADR (HIVE031/035/037/039)
├── 16-auditeur-securite.md            # 4 ADR (HIVE004/025/026/056)
├── 17-debugger.md                     # 3 ADR (HIVE032/035/038)
│
│ # NIVEAU 5 : DOCUMENTATION & COMMUNICATION
├── 24-redacteur-doc-fonctionnelle.md  # Guides utilisateur, tutoriels, FAQ
├── 25-redacteur-doc-technique.md      # API docs, ADR, architecture
└── 26-release-manager.md              # Releases, changelog, communication  ← NOUVEAU
```

## Hiérarchie

```
                    ┌─────────────────────────┐
                    │   01-directeur-projet   │ ← Orchestration
                    └───────────┬─────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        ▼                       ▼                       ▼
┌───────────────┐     ┌─────────────────────┐     ┌─────────────┐
│02-product-owner│    │03-architecte-ddd    │    │04-arch-api  │
│ (Agile methods)│    │    (11 ADR)         │    │  (12 ADR)   │
└───────────────┘     └─────────────────────┘    └─────────────┘
                                │
    ┌───────┬───────┬───────┬───┼───┬───────┬───────┬───────┐
    ▼       ▼       ▼       ▼   ▼   ▼       ▼       ▼       ▼
┌──────┐┌──────┐┌──────┐┌──────┐┌──────┐┌──────┐┌──────┐┌──────┐
│05-be ││06-fe ││07-tbe││08-tfe││09-plat││10-k8s││11-etl││18-sre│
│14 ADR││2 ADR ││7 ADR ││4 ADR ││      ││2 ADR ││      ││HIVE32│
└──────┘└──────┘└──────┘└──────┘└──────┘└──────┘└──────┘└──────┘
┌──────┐┌──────┐┌──────┐
│19-iac││20-ai ││21-ops│  ← NOUVEAUX
│HIVE43││HIVE51││CI/CD │
└──────┘└──────┘└──────┘
                                │
    ┌────────────┬──────────────┼──────────────┬────────────┐
    ▼            ▼              ▼              ▼            ▼
┌────────┐  ┌────────┐  ┌────────┐  ┌────────┐  ┌────────┐
│12-revue│  │13-qa   │  │14-stat │  │15-perf │  │16-sec  │
│ALL ADR │  │4 ADR   │  │2 ADR   │  │4 ADR   │  │4 ADR   │
└────────┘  └────────┘  └────────┘  └────────┘  └────────┘
                        ┌────────┐
                        │17-debug│
                        │3 ADR   │
                        └────────┘
```

## Couverture des Domaines Hive

| Domaine | Agents responsables |
|---------|---------------------|
| **ETL/ESB/API workflows** | 11-ingenieur-data-etl |
| **RAG et Agents IA** | 20-ingenieur-genai-agents |
| **Déploiements K8s** | 10-architecte-kubernetes-helm |
| **Infrastructure multi-cloud** | 19-architecte-cloud-infrastructure |
| **Observabilité** | 18-ingenieur-sre-observabilite |
| **CI/CD et GitOps** | 21-ingenieur-devops-cicd |
| **Microservices lancés** | 09-platform-engineer, 10-k8s-helm |
| **Expérience Utilisateur** | 22-designer-ux-ui |
| **Veille ETL/iPaaS/IA** | 23-analyste-veille-strategique |
| **Documentation utilisateur** | 24-redacteur-doc-fonctionnelle |
| **Documentation technique** | 25-redacteur-doc-technique |
| **Releases et communication** | 26-release-manager |
| **Orchestration agents** | 00-workflow-orchestrator |

## Matrice ADR → Agents (65 ADR)

Voir `workflow-orchestrator.md` pour la matrice complète.

**Couverture** : 65 ADR (HIVE000-HIVE064) tous assignés à au moins un agent.

## Utilisation

### Lancer une tâche depuis un Epic GitHub

```
@01-directeur-projet Lance l'EPIC #82 (Cloud Runtime Implementation)
```

### Lancer une tâche depuis la documentation

```
@01-directeur-projet Lance la tâche documentation/epics/EPIC-082-cloud-runtime-implementation/
```

### Créer un ticket

```
@02-product-owner Crée les User Stories pour la fonctionnalité X
```

### Développer une feature

```
@05-dev-backend-php Implémente le ticket #42 sur la branche feature/42-xxx
```

## Responsabilités des agents

Chaque agent **DOIT** :

### Gestion des tickets GitHub (OBLIGATOIRE)

1. **Synchroniser le statut** du ticket dans le projet GitHub V2 :
   - Passer à **"In Progress"** dès le début du travail
   - Passer à **"Requires Feedback"** si en attente de précisions ou décisions humaines
   - Passer à **"Done"** quand le travail est terminé

2. **Commenter** le ticket avec les avancements significatifs

3. **Lier** les PRs aux tickets via la fonctionnalité **"Development"** de GitHub (OBLIGATOIRE) :
   - Cette liaison est **DIFFÉRENTE** des simples mentions textuelles (`Closes #XX`)
   - Utiliser `gh pr edit` pour créer la liaison officielle après création de la PR

### Liaison PR ↔ Issue via "Development" (OBLIGATOIRE)

**IMPORTANT** : Les PR DOIVENT être liées aux issues via la fonctionnalité "Development" de GitHub.

#### Méthode 1 : Mots-clés dans le body de la PR (RECOMMANDÉ)

Inclure ces mots-clés dans le body de la PR pour créer automatiquement le lien "Development" :

```markdown
## Related issues

Closes #<TASK_NUMBER>      <!-- Ferme automatiquement l'issue au merge -->
Part of #<US_NUMBER>       <!-- Référence l'US parente sans la fermer -->
Resolves #<ISSUE_NUMBER>   <!-- Alternative à Closes -->
Fixes #<ISSUE_NUMBER>      <!-- Pour les bugs -->
```

**Exemple complet** :
```markdown
## Summary

- Implémentation du composant Column...

## Test plan

- [x] Tests passent

## Related issues

Closes #35
Part of #18
```

#### Méthode 2 : Via l'interface GitHub

Si les mots-clés n'ont pas été inclus :
1. Aller sur la page de la PR
2. Dans la sidebar droite, section "Development"
3. Cliquer sur "Link an issue"
4. Sélectionner l'issue à lier

#### Vérification

Le lien "Development" est correctement créé quand :
- L'issue apparaît dans la sidebar "Development" de la PR
- La PR apparaît dans la sidebar "Development" de l'issue
- L'issue se ferme automatiquement au merge (si "Closes" utilisé)

### Conventions de code

4. **Utiliser** Conventional Comments dans les tickets/PR
5. **Créer** des commits Conventional Commits
6. **Respecter** Gitflow pour les branches
7. **Valider** la conformité aux ADR assignés

### Workflow type

```bash
# 1. Début de travail : mettre à jour le statut → "In Progress"
gh project item-edit --project-id "PVT_kwHOAAJTL84BNyIQ" --id "<ITEM_ID>" \
  --field-id "PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ" --single-select-option-id "47fc9ee4"

# 2. Travailler sur le code...

# 3. Si question/blocage : mettre en "Requires Feedback"
gh project item-edit --project-id "PVT_kwHOAAJTL84BNyIQ" --id "<ITEM_ID>" \
  --field-id "PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ" --single-select-option-id "56937311"

# 4. Créer la PR avec les mots-clés de liaison dans le body
# OBLIGATOIRE : Inclure "Closes #XX" et "Part of #YY" dans le body
gh pr create --title "feat: ..." --body "## Summary
...

## Related issues

Closes #<TASK_NUMBER>
Part of #<US_NUMBER>"

# 5. Fin du travail : mettre en "Done"
gh project item-edit --project-id "PVT_kwHOAAJTL84BNyIQ" --id "<ITEM_ID>" \
  --field-id "PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ" --single-select-option-id "98236657"
```

## Démarrage de Session

Chaque nouvelle session de travail suit ce workflow automatique :

1. **Analyse** de la demande utilisateur
2. **Proposition** du type de ticket et titre
3. **Création** automatique du ticket via MCP GitHub
4. **Ajout** au projet #10 avec statut "Ready"
5. **Création** de la branche `feature/{numero}-{description}`
6. **Notification** à l'utilisateur du numéro de ticket

Voir `.cursor/rules/session-workflow.md` pour les détails complets.

## Liens

- Workflow session : `.cursor/rules/session-workflow.md`
- Workflow orchestration : `workflow-orchestrator.md`
- ADR : `architecture/HIVE*.md`
- **EPICs GitHub** : https://github.com/gplanchat/hive/issues?q=label%3Aepic
- **Documentation Epics** : `documentation/epics/`
- **Documentation Tracking** : `documentation/tracking/`
- Règles : `.cursorrules`
- Projet GitHub : https://github.com/users/gplanchat/projects/10
