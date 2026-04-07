# DUR000 — ADR management process

## Status

Accepted

## Context

The **Durable** component needs a clear framework to document architecture decisions, ensure traceability, and align code changes with explicit choices.

## Decision

**Architecture Decision Records (ADRs)** for the Durable project are numbered with the **`DUR`** prefix, followed by a three-digit identifier and a short kebab-case title.

### Location

- Directory: `documentation/adr/`
- Files: `DUR{NNN}-{short-title}.md` (e.g. `DUR001-event-store-and-cursor.md`)

### Numbering

- **DUR000**: this document (meta-process)
- **DUR001 onward**: sequential numbering; do not reuse a withdrawn or obsolete number

### META documents

When a decision needs more detail than fits in one file, supplementary documents may live in a subfolder:

- `documentation/adr/DUR{NNN}-{short-title}/`
- Files: `DUR{NNN}-META{MM}-{topic}.md` (META numbered with two digits)

### Recommended ADR structure

1. Title and identifier
2. Status (draft, proposed, accepted, deprecated, superseded)
3. Context
4. Decision
5. Consequences (positive, negative, follow-up as needed)

### Index

The up-to-date ADR list is maintained in `documentation/INDEX.md`.

## Consequences

- Any major architectural decision in the Durable scope should be reflected by a new ADR or an update to an existing ADR.
- Future implementations can refer to these records without relying on documents outside the repository.
