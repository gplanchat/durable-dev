# ADR management process

ADR001-adr-management-process
===

Introduction
---

This **Architecture Decision Record** establishes the foundations for managing Architecture Decision Records (ADRs) within the Durable project. The Durable project provides a component and Symfony bundle for durable execution (workflows and activities), without a RoadRunner dependency. This process ensures consistency, traceability, and clear communication of architectural decisions.

This document is the meta-ADR that governs all other ADRs in the Durable project.

ADR structure and organization
---

### Location and naming

All ADRs **MUST** be stored in the `documentation/adr/` directory at the project root.

**Naming convention**: `ADR{number}-{short-title}.md`
- Three-digit numbers (e.g. `ADR001`, `ADR002`, `ADR042`)
- Short titles in kebab-case (lowercase, hyphens)
- Examples: `ADR001-adr-management-process.md`, `ADR002-coding-standards.md`

### Numbering

- **ADR001**: Reserved for this document (ADR process)
- **ADR002+**: Assigned sequentially for each new decision
- **Retired numbers**: Never reuse a number, even if an ADR is superseded or deprecated

### Folder structure

```
documentation/
├── INDEX.md
├── LIFECYCLE.md
├── adr/
│   ├── ADR001-adr-management-process.md
│   ├── ADR002-coding-standards.md
│   └── ...
├── wa/
├── ost/
└── prd/
```

### Meta-documents

When an ADR needs supplementary documentation, meta-documents **MAY** be created in a subfolder `ADR{number}-{short-title}/` using the pattern `ADR{number}-META{nn}-{title}.md`.

ADR format and template
---

### Required sections

Each ADR **MUST** include:
1. **Title** followed by `===`
2. **Introduction**: context and problem
3. **Body sections**: decision and rationale
4. **References**: external links and related documents

### Editorial standards

- Clear, concise, professional language (French or English)
- Audience: developers working on the Durable project
- Perspective: present tense for current decisions
- Objectivity: facts and rationale

ADR lifecycle
---

### Creation process

1. Identify the need for an architectural decision
2. Draft the ADR using the established format
3. Assign the next sequential number
4. Team review
5. Maintainer approval
6. Update `documentation/INDEX.md`

### Superseding process

1. Create a new ADR with the new decision
2. Mark the old ADR as superseded
3. Reference the ADR that replaces it
4. Keep both documents for traceability

Maintenance
---

- **INDEX.md**: maintain the index of all documents
- **Status**: indicate Active, Superseded, or Deprecated in ADRs
- **Consistency**: verify process compliance during reviews

References
---

- [Architecture Decision Records](https://adr.github.io/)
- [Documenting Architecture Decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
- [LIFECYCLE.md](../../LIFECYCLE.md)
- [INDEX.md](../../INDEX.md)
