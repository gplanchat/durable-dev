# Session workflow — Cursor rule

This rule defines the automatic session start workflow for the Hive project.

## When it applies

This rule applies automatically when:
- The user starts a new working conversation
- The user asks for a new feature, fix, or task
- The user mentions an Epic or User Story to implement

## Start workflow

### Step 1: Analyze the request

Determine:
1. **Work type**: new feature, bug fix, refactoring, spike, etc.
2. **Scope**: domain(s) involved (Accounting, Authentication, Cloud*, GenAI, Platform, PWA)
3. **Complexity**: T-shirt estimate (XS, S, M, L, XL)
4. **Parent Epic**: if applicable, identify parent Epic (EPIC-XX)

### Step 2: Propose a ticket

Propose to the user:

```
I suggest creating the following ticket:

**Type**: [Epic | Story | Task | Bug | Spike | Subtask | Enabler | Chore]
**Title**: [Prefix] Short description
**Labels**: [type], [domain], [priority if known]
**Parent Epic**: EPIC-XX (if applicable)
**Estimate**: [XS | S | M | L | XL]

Should I create this ticket?
```

### Step 3: Create the ticket

Once the user confirms, use the GitHub MCP:

```
Tool: issue_write
Parameters:
  - method: "create"
  - owner: "gplanchat"
  - repo: "hive"
  - title: "[Type] Description"
  - body: |
      ## Description
      [Detailed description]

      ## Acceptance criteria
      - [ ] Criterion 1
      - [ ] Criterion 2

      ## Parent Epic
      EPIC-XX (if applicable)

      ## Estimate
      Size: [XS | S | M | L | XL]
  - labels: ["type", "domain"]
```

### Step 4: Add to project #10 and assign current iteration

After creation, add the issue to GitHub project #10 **and** assign it to the current iteration:

```bash
# Project constants
PROJECT_ID="PVT_kwHOAAJTL84BNyIQ"
PROJECT_NUMBER="10"
STATUS_FIELD_ID="PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ"
ITERATION_FIELD_ID="PVTIF_lAHOAAJTL84BNyIQzg8sGKQ"

# 1. Add issue to project
gh project item-add $PROJECT_NUMBER --owner gplanchat \
  --url "https://github.com/gplanchat/hive/issues/<ISSUE_NUMBER>"

# 2. Get item ID
ITEM_ID=$(gh project item-list $PROJECT_NUMBER --owner gplanchat --format json | \
  python3 -c "import json,sys; data=json.load(sys.stdin); \
  [print(i['id']) for i in data['items'] if i['content'].get('number')==<ISSUE_NUMBER>]")

# 3. Get current iteration
CURRENT_ITERATION=$(gh api graphql -f query='query { user(login: "gplanchat") { projectV2(number: 10) { field(name: "Iteration") { ... on ProjectV2IterationField { configuration { iterations { id title startDate duration } } } } } } }' | \
  python3 -c "import json,sys,time; data=json.load(sys.stdin); now=time.time(); \
  iters=data['data']['user']['projectV2']['field']['configuration']['iterations']; \
  current=[i for i in iters if time.mktime(time.strptime(i['startDate'],'%Y-%m-%d')) <= now and time.mktime(time.strptime(i['startDate'],'%Y-%m-%d')) + (i['duration']*86400) >= now]; \
  print(current[0]['id'] if current else '')")

# 4. Assign to current iteration
gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
  --field-id "$ITERATION_FIELD_ID" --iteration-id "$CURRENT_ITERATION"

# 5. Set status to "Ready" (or "In Progress" if starting immediately)
gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
  --field-id "$STATUS_FIELD_ID" --single-select-option-id "f75ad846"  # Todo/Ready
```

**REQUIREMENTS**:
- **Project URL**: https://github.com/users/gplanchat/projects/10
- **Iteration**: ALWAYS assign to the **current** iteration (active sprint)
- **Initial status**: "Ready" (or "In Progress" if work starts immediately)

### Step 5: Create branch

```bash
git checkout develop
git pull origin develop
git checkout -b feature/{number}-{short-description}
```

Branch format by type:
- Epic/Story/Task: `feature/{number}-{description}`
- Bug: `fix/{number}-{description}`
- Spike: `spike/{number}-{description}`
- Enabler/Chore: `chore/{number}-{description}`

### Step 6: Confirm

Tell the user:

```
Ticket #{number} created and added to project #10.
Branch: feature/{number}-{description}
Status: Ready

Ready to work on this task. Should I start?
```

## Development workflow

### While working

1. **Regular commits** referencing the ticket: `feat(scope): description #number`
2. **Update status** to "In Progress" on first commit
3. **Documentation** in `documentation/requirements/` for new features

### Opening a PR

When work is done:

1. **Push branch**: `git push -u origin HEAD`
2. **Open PR** via GitHub MCP:
   - Title: `feat(scope): description #number`
   - Base: `develop`
   - Body using project PR template
3. **Update status** to "In Review (AI)"
4. **Self-review** with comments
5. **Update status** to "Pending Review"
6. **Notify** the user the PR awaits human review

## Strict rules

### FORBIDDEN for the agent

- **NEVER** merge a PR without human approval
- **NEVER** jump straight to "Done"
- **NEVER** skip human review
- **NEVER** force push to `develop` or `main`
- **NEVER** start work on an issue without assigning it to the **current iteration**

### REQUIRED for the agent — GitHub project management

**CRITICAL**: These rules keep project tracking smooth and are **NON-NEGOTIABLE**.

#### Taking an issue

- **ALWAYS** assign the issue to the **current iteration** (active sprint) **BEFORE** starting work
- **ALWAYS** set status to "In Progress" at work start
- **ALWAYS** keep status updated for the whole task

#### Status updates (MANDATORY)

| Event | Required action |
|-------|-----------------|
| Issue taken | → Current iteration + **In Progress** |
| Question / blocked | → **Requires Feedback** |
| Resumed after reply | → **In Progress** |
| PR opened | → **In Progress** (keep) |
| Work complete | → **Done** (after human merge) |

#### Quick commands

```bash
PROJECT_ID="PVT_kwHOAAJTL84BNyIQ"
STATUS_FIELD_ID="PVTSSF_lAHOAAJTL84BNyIQzg8rZDQ"
ITERATION_FIELD_ID="PVTIF_lAHOAAJTL84BNyIQzg8sGKQ"

STATUS_TODO="f75ad846"
STATUS_IN_PROGRESS="47fc9ee4"
STATUS_REQUIRES_FEEDBACK="56937311"
STATUS_DONE="98236657"

gh project item-edit --project-id "$PROJECT_ID" --id "<ITEM_ID>" \
  --field-id "$STATUS_FIELD_ID" --single-select-option-id "<STATUS_OPTION>"

gh project item-edit --project-id "$PROJECT_ID" --id "<ITEM_ID>" \
  --field-id "$ITERATION_FIELD_ID" --iteration-id "<ITERATION_ID>"
```

### REQUIRED for the agent — Commits and PRs

- **ALWAYS** atomic commits (smallest logical unit)
- **ALWAYS** Conventional Commits: `type(scope): description #number`
- **ALWAYS** reference the ticket number in each commit
- **ALWAYS** open PRs to `develop` (not `main`)
- **ALWAYS** document changes in the PR body
- **ALWAYS** include `Closes #XX` and `Part of #YY` in the PR body
- **ALWAYS** go through "In Review (AI)" then "Pending Review"

### Atomic commit examples

```bash
# GOOD — atomic commits
git commit -m "feat(auth): add User entity #42"
git commit -m "feat(auth): add UserRepository interface #42"
git commit -m "feat(auth): add InMemoryUserRepository #42"
git commit -m "test(auth): add UserRepository unit tests #42"

# BAD — oversized commit
git commit -m "feat(auth): implement user management #42"  # Too vague and too large
```

## Templates

### Issue body template

```markdown
## Description

[Clear, concise description of the work]

## Context

[Why this work is needed]

## Acceptance criteria

- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Unit tests pass
- [ ] PHPStan clean
- [ ] Documentation updated (if applicable)

## Parent Epic

EPIC-XX (if applicable)

## Estimate

- **Size**: [XS | S | M | L | XL]
- **Priority**: [High | Medium | Low]

## Technical notes

[Any relevant technical detail]
```

### PR body template

```markdown
## Summary

[1–3 bullet summary of changes]

## Related Issue

Closes #{number}

## Changes

- [List of files/components touched]

## Test plan

- [ ] Unit tests added/updated
- [ ] Integration tests pass
- [ ] PHPStan clean
- [ ] PHP-CS-Fixer applied

## Screenshots

[If UI changed, screenshots]

## Checklist

- [ ] Code follows project ADRs
- [ ] Tests cover happy path and errors
- [ ] Documentation updated
- [ ] Commits follow Conventional Commits
```

## GitHub Epic integration

Project EPICs are managed via:
- **GitHub issues** with label `epic`: https://github.com/gplanchat/hive/issues?q=label%3Aepic
- **Documentation** under `documentation/epics/` (README, specs, prompts)

### Starting from an existing Epic

1. **Check** whether the request matches an existing Epic (search issues or `documentation/epics/`)
2. **Reference** the matching Epic in the created ticket
3. **Create** Story/Task items as children of the Epic

### Current Epic list

| # | Epic | Documentation |
|---|------|---------------|
| #15 | Chakra UI Datagrid system | `documentation/epics/EPIC-015-chakra-datagrid/` |
| #76 | FinOps consolidation (Accounting) | `documentation/epics/EPIC-076-accounting-finops-consolidation/` |
| #77 | FinOps credit and thresholds (Accounting) | `documentation/epics/EPIC-077-accounting-finops-threshold/` |
| #78 | FinOps OVHCloud | `documentation/epics/EPIC-078-cloud-finops-ovh/` |
| #79 | Region supervision (Cloud Management) | `documentation/epics/EPIC-079-cloud-management-supervision/` |
| #80 | Service tracking (Cloud Platform) | `documentation/epics/EPIC-080-cloud-platform-implementation/` |
| #81 | OCI compilation (Cloud Runtime) | `documentation/epics/EPIC-081-cloud-runtime-compilation/` |
| #82 | Cloud Runtime implementation | `documentation/epics/EPIC-082-cloud-runtime-implementation/` |
| #83 | Reconciliation (Cloud Runtime) | `documentation/epics/EPIC-083-cloud-runtime-reconciliation/` |
| #84 | Data Engineering | `documentation/epics/EPIC-084-data-engineering/` |
| #85 | Deployment and provisioning | `documentation/epics/EPIC-085-deployment-architecture/` |
| #86 | Sales Manager | `documentation/epics/EPIC-086-sales-manager/` |

### Epic documentation layout

Each `documentation/epics/EPIC-XXX-name/` folder contains:
- `README.md`: description, scope, deliverables, ADRs to follow, cross-links
- `prompt.md`: agent instructions (if applicable)
- Other files: specific functional/technical docs

### Cross-references

- EPICs may depend on other EPICs (documented in README)
- ADR references live in `architecture/HIVE*.md`
- Cross-cutting tracking in `documentation/tracking/`
