# WA003 — GitHub epics, tasks, and project tracking

## Status

Accepted

## Context

Work is tracked with **GitHub Issues** and, when used, **GitHub Projects**. Without shared conventions, titles, bodies, and parent links become inconsistent, boards are hard to read, and planning documents drift from reality. This WA captures **portable rules** for structuring epics, tasks, and stories and keeping them aligned with a **planning source** and a **project board**. It generalises practices previously described in project-specific planning material (see **Source** below).

## Agreement

### Issue kinds and title prefixes

| Kind | Title prefix | Purpose |
|------|----------------|---------|
| **Epic** | `[EPIC]` | Large outcome spanning multiple tasks/stories; may map to a bounded area or roadmap theme. |
| **Task** | `[Task]` | Concrete work unit (often technical or incremental). |
| **Story** | `[Story]` | User- or outcome-oriented slice with acceptance criteria. |

Titles remain **clear and scoped** (product area, feature); prefixes are **mandatory** for new issues of these kinds.

### Labels

- Use **consistent** labels for **type** (`task`, `story`, …) and **domain** or **theme** (`pwa`, `docs`, `genai`, …) as defined by maintainers for the repository.
- When work belongs to a named programme or epic family, use **labels** such as `epic-078`, `epic-082` (or the repository’s equivalent) so filters and views stay usable.

### Parent / child linkage

- **Tasks** and **stories** that roll up to an epic **must** state the parent in the issue body, e.g. `Parent Epic: #NN` (use the epic’s **issue number**).
- Prefer **GitHub sub-issues** (or the repository’s supported hierarchy mechanism) to link each child issue to its epic so the graph is visible on GitHub, not only in prose.

### GitHub Project

- **Add** new issues that belong to the team’s workflow to the **designated GitHub Project** for this repository (maintainers define which project; automation may apply).
- Keep **status** and **iteration** fields on the project updated when the team uses them (see project workflow rules if any).

### Issue bodies

**Epics** should include at minimum:

- A short link to the **planning or tracking document** that defines the epic (path in-repo or stable URL).
- **Objective** (why this epic exists).
- **Main deliverables** (bullet list).
- **References** (e.g. deeper docs, ADRs, epic README paths).

**Tasks** and **stories** should include at minimum:

- The same **plan** link when the item is driven by a planning document.
- Optional **`Todo id`** (or equivalent id from the plan table) for traceability.
- **Description** of the work.
- For **stories**, **acceptance criteria** as checkboxes.
- **Parent Epic** line as in **Parent / child linkage**.

Adapt fields if a template is mandated by the repository (e.g. GitHub issue forms).

### Planning document hygiene

- When a planning document lists work items and GitHub issues are created from it, **update** that document with **Issue #** identifiers as issues are filed so the plan and GitHub stay aligned.

### Scope

- This WA governs **how** issues and project items are structured; it does **not** prescribe a particular product roadmap. Repository-specific scripts or MCP tools may implement these rules.

## Consequences

- New epics, tasks, and stories **should** follow the prefixes, body structure, and parent links above unless a superseding WA or template exists.
- Reviews of planning or delivery may **reject** ad hoc titles or missing parent links for hierarchical work.

## References

- [WA001 — English language for project documentation](WA001-english-language-documentation.md)
- [documentation/LIFECYCLE.md](../LIFECYCLE.md)
- [documentation/INDEX.md](../INDEX.md)

## Source (non-normative)

Principles were distilled from `documentation/archive/PLANIFICATION_EPICS_TACHES_GITHUB.md` (2026-01-31). That file may contain **repository-specific** issue lists; **this WA** is the normative, **generalised** form for this project.
