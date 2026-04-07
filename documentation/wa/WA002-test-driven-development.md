# WA002 — Test-driven development (TDD)

## Status

Accepted

## Context

The Durable component needs **safe iteration** on deterministic workflows, adapters, and ports. **Test-driven development** turns requirements into **executable specifications**, keeps refactors safe, and avoids untested production paths. This WA defines **how** we develop: the **TDD cycle** and **nomenclature** complement **what** good tests look like (**DUR009**, **DUR010**).

## Agreement

### The TDD cycle (mandatory)

For **behaviour-changing** work on production code, follow this loop until the feature or fix is complete:

1. **Red** — Add or extend an **automated test** that fails and expresses the **next** desired behaviour (one small step).
2. **Green** — Implement the **minimum** production code required to make that test pass.
3. **Refactor** — Improve structure and names with **all** relevant tests still passing.

Repeat. Do not skip **Red** for new behaviour.

### Nomenclature and test shape

- **Test names** state **observable behaviour** or **rule** under test (what must hold), not private implementation details. Follow PHPUnit and **PER** naming for classes and methods (**DUR008**, **DUR009**).
- **One primary intent per test method**; if a name needs “and”, split into another test.
- Structure tests so intent is obvious: **Arrange → Act → Assert** (equivalent to **Given → When → Then**). Name variables and data to match domain language.

### Rules

- **No new production behaviour** without a **failing test written first** (or extended) for that behaviour.
- Avoid speculative production code; implement only what the current failing test demands, then generalise in **Refactor** when tests still pass.
- **Refactoring** is allowed only on **Green**; after each refactor step, the suite must stay green.

### Relationship to other normative documents

- **DUR009** — Framework (PHPUnit), determinism, doubles, isolation: **quality** of tests.
- **DUR010** — **Pyramid** (unit vs integration vs E2E): TDD applies within each layer; it does not replace layering choices.

### Out of scope (exceptions)

- **Documentation-only** or **comment-only** edits with no executable change.
- **Pure mechanical** changes (e.g. rename without behaviour change) where existing tests are updated in the same change and no new behaviour is introduced.
- **Time-boxed spikes**: exploratory code must be **replaced** by TDD-led implementation before merge, unless a **recorded exception** (e.g. ADR or ticket) applies.

### Code review (human reviewers and self-review)

Reviewers treat **this WA** as a **first-class** check alongside **ADRs** and **`documentation/wa/`** compliance (**LIFECYCLE**).

**Verify**

- **Coverage of behaviour**: New or changed **observable** production behaviour has **automated tests** that specify it and would **fail** without that behaviour (the **Red** step is reflected in history: tests land with or before the production change in the same PR when practical).
- **Nomenclature**: Test names and structure match **§ Nomenclature and test shape** above and **DUR009**.
- **Exceptions**: Confirm the change is not only **Out of scope**; if it is a **spike**, a **recorded exception** or replacement plan exists.

**Severity (for feedback)**

| Finding | Typical severity |
|--------|------------------|
| Production behaviour added/changed **without** tests that encode it | **Blocking** — request tests first (TDD), or document an approved exception |
| Tests present but **unclear** intent, weak names, or oversized methods | **Non-blocking** — suggest refactor per DUR009 |
| PR bundles unrelated refactors with behaviour change | **Non-blocking** — suggest smaller follow-ups |

Use **Conventional Comments** (e.g. `issue (blocking):` / `suggestion:`) so authors can triage quickly.

**Self-review** before requesting human review: run the same checks locally; do not rely on reviewers to discover missing TDD.

## Consequences

- Code review may **reject** increments that add or change production behaviour without an evident **Red → Green** sequence (tests first).
- The PHPUnit suite in CI remains the **safety net** for **Refactor**; maintainers may add stricter checks as needed.

## References

- [DUR009 — Testing standards](../adr/DUR009-testing-standards.md)
- [DUR010 — Test pyramid](../adr/DUR010-test-pyramid.md)
- [WA001 — English language for project documentation](WA001-english-language-documentation.md)
- [documentation/LIFECYCLE.md](../LIFECYCLE.md)
- [documentation/INDEX.md](../INDEX.md)
- Cursor: `.cursor/rules/code-review-wa002-tdd.mdc` (review and self-review must apply this WA)
