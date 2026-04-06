# Session Workflow - Règle Cursor

Cette règle définit le workflow automatique de démarrage de session pour le projet Hive.

## Déclenchement

Cette règle s'applique automatiquement quand :
- L'utilisateur démarre une nouvelle conversation de travail
- L'utilisateur demande une nouvelle fonctionnalité, correction ou tâche
- L'utilisateur mentionne un Epic ou une User Story à implémenter

## Workflow de Démarrage

### Étape 1 : Analyse de la demande

Analyser la demande de l'utilisateur pour déterminer :
1. **Type de travail** : nouvelle fonctionnalité, bug fix, refactoring, spike, etc.
2. **Scope** : domaine(s) concerné(s) (Accounting, Authentication, Cloud*, GenAI, Platform, PWA)
3. **Complexité** : estimation T-shirt (XS, S, M, L, XL)
4. **Epic parent** : si applicable, identifier l'Epic parent (EPIC-XX)

### Étape 2 : Proposition de ticket

Proposer à l'utilisateur :

```
Je propose de créer le ticket suivant :

**Type** : [Epic | Story | Task | Bug | Spike | Subtask | Enabler | Chore]
**Titre** : [Préfixe] Description concise
**Labels** : [type], [domaine], [priorité si connue]
**Epic parent** : EPIC-XX (si applicable)
**Estimation** : [XS | S | M | L | XL]

Voulez-vous que je crée ce ticket ?
```

### Étape 3 : Création du ticket

Une fois validé par l'utilisateur, utiliser le MCP GitHub :

```
Outil : issue_write
Paramètres :
  - method: "create"
  - owner: "gplanchat"
  - repo: "hive"
  - title: "[Type] Description"
  - body: |
      ## Description
      [Description détaillée]
      
      ## Critères d'acceptation
      - [ ] Critère 1
      - [ ] Critère 2
      
      ## Epic parent
      EPIC-XX (si applicable)
      
      ## Estimation
      Size: [XS | S | M | L | XL]
  - labels: ["type", "domaine"]
```

### Étape 4 : Ajout au projet #10 et assignation à l'itération courante

Après création, ajouter l'issue au projet GitHub #10 ET l'assigner à l'itération courante :

```bash
# Constantes du projet
PROJECT_ID="PVT_kwHOAAJTL84BNyIQ"
PROJECT_NUMBER="10"
STATUS_FIELD_ID="PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ"
ITERATION_FIELD_ID="PVTIF_lAHOAAJTL84BNyIQzg8sGKQ"

# 1. Ajouter l'issue au projet
gh project item-add $PROJECT_NUMBER --owner gplanchat \
  --url "https://github.com/gplanchat/hive/issues/<ISSUE_NUMBER>"

# 2. Récupérer l'item ID
ITEM_ID=$(gh project item-list $PROJECT_NUMBER --owner gplanchat --format json | \
  python3 -c "import json,sys; data=json.load(sys.stdin); \
  [print(i['id']) for i in data['items'] if i['content'].get('number')==<ISSUE_NUMBER>]")

# 3. Récupérer l'itération courante
CURRENT_ITERATION=$(gh api graphql -f query='query { user(login: "gplanchat") { projectV2(number: 10) { field(name: "Iteration") { ... on ProjectV2IterationField { configuration { iterations { id title startDate duration } } } } } } }' | \
  python3 -c "import json,sys,time; data=json.load(sys.stdin); now=time.time(); \
  iters=data['data']['user']['projectV2']['field']['configuration']['iterations']; \
  current=[i for i in iters if time.mktime(time.strptime(i['startDate'],'%Y-%m-%d')) <= now and time.mktime(time.strptime(i['startDate'],'%Y-%m-%d')) + (i['duration']*86400) >= now]; \
  print(current[0]['id'] if current else '')")

# 4. Assigner à l'itération courante
gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
  --field-id "$ITERATION_FIELD_ID" --iteration-id "$CURRENT_ITERATION"

# 5. Passer le statut à "Ready" (ou "In Progress" si on commence immédiatement)
gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
  --field-id "$STATUS_FIELD_ID" --single-select-option-id "f75ad846"  # Todo/Ready
```

**OBLIGATIONS** :
- **URL du projet** : https://github.com/users/gplanchat/projects/10
- **Itération** : TOUJOURS assigner à l'itération courante (sprint actif)
- **Statut initial** : "Ready" (ou "In Progress" si travail immédiat)

### Étape 5 : Création de la branche

Créer la branche de travail :

```bash
git checkout develop
git pull origin develop
git checkout -b feature/{numero}-{description-courte}
```

Format de branche selon le type :
- Epic/Story/Task : `feature/{numero}-{description}`
- Bug : `fix/{numero}-{description}`
- Spike : `spike/{numero}-{description}`
- Enabler/Chore : `chore/{numero}-{description}`

### Étape 6 : Confirmation

Informer l'utilisateur :

```
Ticket #{numero} créé et ajouté au projet #10.
Branche : feature/{numero}-{description}
Statut : Ready

Je suis prêt à travailler sur cette tâche. Voulez-vous commencer ?
```

## Workflow de Développement

### Pendant le travail

1. **Commits réguliers** avec référence au ticket : `feat(scope): description #numero`
2. **Mise à jour du statut** en "In Progress" dès le premier commit
3. **Documentation** dans `documentation/requirements/` si nouvelle fonctionnalité

### Création de PR

Quand le travail est terminé :

1. **Push de la branche** : `git push -u origin HEAD`
2. **Création de la PR** via MCP GitHub :
   - Titre : `feat(scope): description #numero`
   - Base : `develop`
   - Body avec template PR du projet
3. **Mise à jour du statut** en "In Review (AI)"
4. **Auto-revue** du code avec commentaires
5. **Mise à jour du statut** en "Pending Review"
6. **Notification** à l'utilisateur que la PR attend une revue humaine

## Règles Strictes

### INTERDIT pour l'agent

- **JAMAIS** merger une PR sans validation humaine
- **JAMAIS** passer directement en "Done"
- **JAMAIS** skip la revue humaine
- **JAMAIS** force push sur `develop` ou `main`
- **JAMAIS** commencer à travailler sur une issue sans l'assigner à l'itération courante

### OBLIGATOIRE pour l'agent — Gestion du Projet GitHub

**CRITIQUE** : Ces obligations permettent un suivi fluide du projet et sont NON NÉGOCIABLES.

#### Prise en charge d'une issue

- **TOUJOURS** assigner l'issue à l'**itération courante** (sprint actif) AVANT de commencer le travail
- **TOUJOURS** passer le statut à "In Progress" dès le début du travail
- **TOUJOURS** maintenir le statut à jour pendant toute la durée du travail

#### Mise à jour des statuts (OBLIGATOIRE)

| Événement | Action obligatoire |
|-----------|-------------------|
| Prise en charge d'une issue | → Itération courante + **In Progress** |
| Question/blocage | → **Requires Feedback** |
| Reprise après réponse | → **In Progress** |
| PR créée | → **In Progress** (maintenu) |
| Travail terminé | → **Done** (humain merge) |

#### Commandes rapides

```bash
# Constantes
PROJECT_ID="PVT_kwHOAAJTL84BNyIQ"
STATUS_FIELD_ID="PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ"
ITERATION_FIELD_ID="PVTIF_lAHOAAJTL84BNyIQzg8sGKQ"

# Options de statut
STATUS_TODO="f75ad846"
STATUS_IN_PROGRESS="47fc9ee4"
STATUS_REQUIRES_FEEDBACK="56937311"
STATUS_DONE="98236657"

# Mettre à jour le statut
gh project item-edit --project-id "$PROJECT_ID" --id "<ITEM_ID>" \
  --field-id "$STATUS_FIELD_ID" --single-select-option-id "<STATUS_OPTION>"

# Assigner à l'itération
gh project item-edit --project-id "$PROJECT_ID" --id "<ITEM_ID>" \
  --field-id "$ITERATION_FIELD_ID" --iteration-id "<ITERATION_ID>"
```

### OBLIGATOIRE pour l'agent — Commits et PR

- **TOUJOURS** créer des commits atomiques (plus petite unité logique possible)
- **TOUJOURS** utiliser Conventional Commits : `type(scope): description #numero`
- **TOUJOURS** référencer le numéro de ticket dans chaque commit
- **TOUJOURS** créer une PR vers `develop` (pas `main`)
- **TOUJOURS** documenter les changements dans la PR
- **TOUJOURS** inclure `Closes #XX` et `Part of #YY` dans le body de la PR
- **TOUJOURS** passer par "In Review (AI)" puis "Pending Review"

### Exemples de commits atomiques

```bash
# BON - commits atomiques
git commit -m "feat(auth): add User entity #42"
git commit -m "feat(auth): add UserRepository interface #42"
git commit -m "feat(auth): add InMemoryUserRepository #42"
git commit -m "test(auth): add UserRepository unit tests #42"

# MAUVAIS - commit trop gros
git commit -m "feat(auth): implement user management #42"  # Trop vague et trop gros
```

## Templates

### Template Body Issue

```markdown
## Description

[Description claire et concise du travail à réaliser]

## Contexte

[Pourquoi ce travail est nécessaire]

## Critères d'acceptation

- [ ] Critère 1
- [ ] Critère 2
- [ ] Tests unitaires passent
- [ ] PHPStan sans erreur
- [ ] Documentation mise à jour (si applicable)

## Epic parent

EPIC-XX (si applicable)

## Estimation

- **Size** : [XS | S | M | L | XL]
- **Priority** : [High | Medium | Low]

## Notes techniques

[Toute information technique pertinente]
```

### Template Body PR

```markdown
## Summary

[Résumé des changements en 1-3 bullet points]

## Related Issue

Closes #{numero}

## Changes

- [Liste des fichiers/composants modifiés]

## Test plan

- [ ] Tests unitaires ajoutés/mis à jour
- [ ] Tests d'intégration passent
- [ ] PHPStan sans erreur
- [ ] PHP-CS-Fixer appliqué

## Screenshots

[Si applicable, captures d'écran des changements UI]

## Checklist

- [ ] Le code suit les ADR du projet
- [ ] Les tests couvrent les cas nominaux et d'erreur
- [ ] La documentation est à jour
- [ ] Les commits suivent Conventional Commits
```

## Intégration avec les Epics GitHub

Les EPICs du projet sont gérés via :
- **Issues GitHub** avec le label `epic` : https://github.com/gplanchat/hive/issues?q=label%3Aepic
- **Documentation** dans `documentation/epics/` (README, spécifications, prompts)

### Workflow de démarrage avec un Epic existant

1. **Vérifier** si la demande correspond à un Epic existant (rechercher dans les issues ou `documentation/epics/`)
2. **Référencer** l'Epic correspondant dans le ticket créé
3. **Créer** les Story/Task comme sous-éléments de l'Epic

### Liste des EPICs actuels

| # | Epic | Documentation |
|---|------|---------------|
| #15 | Système Datagrid Chakra UI | `documentation/epics/EPIC-015-chakra-datagrid/` |
| #76 | Consolidation FinOps (Accounting) | `documentation/epics/EPIC-076-accounting-finops-consolidation/` |
| #77 | Crédit et Seuils FinOps (Accounting) | `documentation/epics/EPIC-077-accounting-finops-threshold/` |
| #78 | FinOps OVHCloud | `documentation/epics/EPIC-078-cloud-finops-ovh/` |
| #79 | Supervision Régions (Cloud Management) | `documentation/epics/EPIC-079-cloud-management-supervision/` |
| #80 | Suivi Services (Cloud Platform) | `documentation/epics/EPIC-080-cloud-platform-implementation/` |
| #81 | Compilation OCI (Cloud Runtime) | `documentation/epics/EPIC-081-cloud-runtime-compilation/` |
| #82 | Implémentation Cloud Runtime | `documentation/epics/EPIC-082-cloud-runtime-implementation/` |
| #83 | Réconciliation (Cloud Runtime) | `documentation/epics/EPIC-083-cloud-runtime-reconciliation/` |
| #84 | Data Engineering | `documentation/epics/EPIC-084-data-engineering/` |
| #85 | Déploiement et Provisionnement | `documentation/epics/EPIC-085-deployment-architecture/` |
| #86 | Sales Manager | `documentation/epics/EPIC-086-sales-manager/` |

### Structure de documentation par Epic

Chaque dossier `documentation/epics/EPIC-XXX-nom/` contient :
- `README.md` : Description, scope, livrables, ADR à respecter, références croisées
- `prompt.md` : Instructions pour les agents (si applicable)
- Autres fichiers : Documentation technique et fonctionnelle spécifique

### Références croisées

- Les EPICs peuvent dépendre d'autres EPICs (documenté dans le README)
- Les références vers les ADR sont dans `architecture/HIVE*.md`
- Le tracking transversal est dans `documentation/tracking/`
