# Document lifecycle and organization

This document describes how architecture documents are created, organized, and linked.

---

## Overview

```
OpenSpec change (design phase)
  openspec/changes/<name>/{proposal.md,tasks.md,specs/}
         │
         ▼
    ┌────────────┐
    │ Which type?│
    └─────┬──────┘
          │
    ┌─────┼─────┐
    ▼     ▼     ▼
  ADR   WA    OST
 (tech) (org) (future)
```

---

## Document types and usage

### OpenSpec change — design phase

**When** : A feature or a refactor is about to be built. Since August 2026 the design phase lives in
`openspec/`, not in `documentation/`.

**Typical content** :
- `proposal.md` — why, what changes, capabilities, impact
- `tasks.md` — the work, numbered, checked off as it lands
- `specs/<capability>/spec.md` — scenarios observable from outside the component
- `design.md` (optional) — which server behaviours were probed, which were assumed

Project context and per-artifact rules are in `openspec/config.yaml`. Once a change ships, its
folder moves to `openspec/changes/archive/<date>-<name>/` and stays there as the record of what
was planned; the decisions it produced are written up as ADRs.

**Example** : `openspec/changes/archive/2026-08-27-workflow-versioning/`.

---

### ADR — Architecture Decision Record

**When** : A technical decision affects architecture (library choice, pattern, stack).

**Typical content** :
- Context and problem
- Options considered
- Decision taken
- Consequences

**Example** : Choosing Symfony Messenger for activity transport.

---

### WA — Working Agreement

**When** : Agreement on how we work or manage the project.

**Typical content** :
- Agreement or convention
- Roles and responsibilities
- Process or workflow

**Example** : Branch naming, review cadence, the agentic loop and its ledgers.

---

### OST — Opportunity Solution Tree

**When** : Exploring a future feature before development.

**Typical content** :
- Opportunity or user goal
- Candidate solutions
- Hypotheses to validate
- Decision tree

**Example** : Temporal as an optional driver, multi-transport.

---

### Other folders

- **`audit/`** — a dated, read-only review of the code base, one file per axis plus a synthesis.
  Each finding is anchored on a verified `file:line`. It records a state at a commit and is not
  updated afterwards.
- **`journal/`** — day files under `inbox/`, material already used for a published post under
  `archive/`. Both are ignored by Git; only the README and `.gitkeep` files are tracked. See
  [journal/README.md](journal/README.md) and the Cursor rule `blog-journal`.
- **`blog/`** — opinion pieces published on the Hugo site next to the user guide: an argument,
  a measurement that changed a design, a reading of something already built.
- **`user/`** — the user guide, published by Hugo. See [HUGO.md](HUGO.md).

---

## Typical lifecycle

### For a new feature

```
1. OST (exploration, optional)
   → Opportunity reflection, possible solutions

2. OpenSpec change (design)
   → proposal.md, tasks.md, specs/

3. Development
   → Implementation, tasks checked off

4. ADR (what was decided)
   → One per decision the change produced

5. Archive
   → openspec/changes/archive/<date>-<name>/
```

### For an isolated technical decision

```
ADR only
→ No mandatory link to an OST or an OpenSpec change
```

### For a working agreement

```
WA only
→ Independent of the feature lifecycle
```

---

## Folder layout

```
documentation/
├── INDEX.md          ← Index of all documents (keep updated)
├── LIFECYCLE.md      ← This document
├── HUGO.md           ← How the user guide is built
├── adr/              ← DUR000-xxx.md, DUR001-xxx.md, … (Architecture Decision Records for this component)
├── wa/               ← WA001-xxx.md, WA002-xxx.md, ...
├── ost/              ← OST001-xxx.md, OST002-xxx.md, ...
├── audit/            ← dated read-only review, one file per axis
├── journal/          ← inbox/ and archive/ day files (ignored by Git)
├── blog/             ← posts published beside the user guide
└── user/             ← user guide source (Hugo)

openspec/
├── config.yaml       ← project context and per-artifact rules
├── specs/            ← current specifications, by capability
└── changes/
    ├── <name>/       ← change in progress: proposal.md, tasks.md, specs/
    └── archive/      ← <date>-<name>/ once shipped
```

---

## Numbering

- **Sequential per type** : For this repository, ADR filenames use the **`DUR`** prefix (`DUR000`, `DUR001`, …) per project convention — see [DUR000](adr/DUR000-adr-management-process.md) and [documentation/INDEX.md](INDEX.md).
- **No gaps** : Do not reuse a removed number
- **Short slug** : Lowercase, hyphens, descriptive (e.g. `temporal-grpc-bridge`)
- **OpenSpec changes** : kebab-case name, prefixed with the archive date once shipped

---

## Cross-references

Documents may reference each other:

- **OST → change** : An OpenSpec change may design the feature explored in an OST
- **change → DUR** : An ADR records a decision made while building a change
- **OST → ADR** : An ADR may record a technical decision stemming from an OST
- **DUR → DUR** : An ADR may supersede or complement another (status “Superseded by DUR00x”)

---

## Maintenance

1. **For each new document** : Update `documentation/INDEX.md`
2. **When a decision is obsolete** : Update the ADR with status “Superseded”
3. **When a change ships** : Archive it under `openspec/changes/archive/`, write the ADRs it produced, and update the OST if needed
