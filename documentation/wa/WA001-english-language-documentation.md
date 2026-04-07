# WA001 — English language for project documentation

## Status

Accepted

## Context

The Durable repository is developed in an international context and relies on shared tooling (Cursor rules, ADRs, working agreements, tracking). A single **written language** for specifications, rules, and operational docs reduces ambiguity and keeps search, review, and automation consistent.

## Agreement

**All** of the following **must be written in English**:

1. **Tracking and journals** — e.g. `documentation/journal/` (inbox entries, README), session notes used as project memory, unless explicitly exempted.
2. **Specifications and architecture** — ADRs (`documentation/adr/`), working agreements (`documentation/wa/`), OSTs, PRDs, and `documentation/LIFECYCLE.md` (and updates to `documentation/INDEX.md`).
3. **Cursor rules** — files under `.cursor/rules/` (`.mdc` and related rule documents).

### Scope clarification

- **Code** (identifiers, user-visible strings) follows product needs; this WA governs **documentation and rule text** in the paths above.
- **`documentation/archive/`** is excluded from mandatory translation of legacy material; new content added there should still follow this WA when it is normative.
- **Third-party quotes** or RFC excerpts may remain in the original language if cited as references.

### Compliance

- New documents and edits in scope **must** use English.
- When migrating from another language, replace or translate in the same change that introduces this WA or in follow-up edits.

## Consequences

- Reviews may reject non-English additions to the listed areas.
- The agent must prefer English for any new text in `.cursor/rules/` and `documentation/` (except archive policy above).

## References

- [documentation/LIFECYCLE.md](../LIFECYCLE.md)
- [documentation/INDEX.md](../INDEX.md)
