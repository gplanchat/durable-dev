# Conventions and reviews

WA001-conventions-and-reviews
===

Introduction
---

This **Working Agreement** defines working conventions and agreements for managing the Durable project. It applies to the development team and contributors.

Naming conventions
---

### Git branches

- `main`: primary branch
- `feature/{ticket-id}-{description}`: features
- `fix/{ticket-id}-{description}`: fixes
- `docs/{description}`: documentation

### Commits

- Messages in French or English
- Format: `type(scope): description`
- Types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`

### Files

- PHP: PascalCase for classes
- Tests: `*Test.php` or `*TestCase.php`
- Configuration: kebab-case for YAML files

Code review
---

- Significant changes **MUST** be reviewed
- ADRs and documentation changes **MUST** be read before merge
- Criteria: ADR compliance, tests, code clarity

Cursor plan management
---

- Documented design phases (ADR, WA, OST, PRD) follow [LIFECYCLE.md](../LIFECYCLE.md)
- Each new document is indexed in [INDEX.md](../INDEX.md)
- Attached Cursor plans are documented under `documentation/`

Responsibilities
---

- **Maintainers**: final ADR validation, releases
- **Contributors**: follow conventions, keep documentation up to date
- **Review**: at least one approval for PRs impacting architecture

References
---

- [INDEX.md](../INDEX.md)
- [LIFECYCLE.md](../LIFECYCLE.md)
- [ADR001 - ADR process](../adr/ADR001-adr-management-process.md)
