# Document lifecycle and organization

This document describes how architecture documents are created, organized, and linked.

---

## Overview

```
Cursor plan (design phase)
         │
         ▼
    ┌────────────┐
    │ Which type?│
    └─────┬──────┘
          │
    ┌─────┼─────┬─────────────┐
    ▼     ▼     ▼             ▼
  ADR   WA    OST           PRD
 (tech) (org) (future)   (shipped)
```

---

## Document types and usage

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

**Example** : Branch naming, review cadence, Cursor plan management.

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

### PRD — Product Requirements Document

**When** : A feature is already built and needs documentation.

**Typical content** :
- Goals and scope
- Functional specifications
- Acceptance criteria
- Implementation status

**Example** : Documenting the durable workflow system after implementation.

---

## Typical lifecycle

### For a new feature

```
1. OST (exploration)
   → Opportunity reflection, possible solutions

2. ADR (if technical decisions)
   → Technical choices tied to the feature

3. Development
   → Implementation

4. PRD (after the fact)
   → Specifications and status of the delivered feature
```

### For an isolated technical decision

```
ADR only
→ No mandatory link to OST or PRD
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
├── adr/              ← ADR001-xxx.md, ADR002-xxx.md, ...
├── wa/               ← WA001-xxx.md, WA002-xxx.md, ...
├── ost/              ← OST001-xxx.md, OST002-xxx.md, ...
└── prd/              ← PRD001-xxx.md, PRD002-xxx.md, ...
```

---

## Numbering

- **Sequential per type** : ADR001, ADR002, ADR003…
- **No gaps** : Do not reuse a removed number
- **Short slug** : Lowercase, hyphens, descriptive (e.g. `babylonjs-choice`)

---

## Cross-references

Documents may reference each other:

- **OST → ADR** : An ADR may record a technical decision stemming from an OST
- **OST → PRD** : A PRD documents the feature explored in an OST
- **ADR → ADR** : An ADR may supersede or complement another (status “Superseded by ADR002”)

---

## Maintenance

1. **For each new document** : Update `documentation/INDEX.md`
2. **When a decision is obsolete** : Update the ADR with status “Superseded”
3. **When an OST feature ships** : Create the matching PRD and update the OST if needed
