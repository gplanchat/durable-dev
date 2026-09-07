# WA006 — English is the working language; French documentation is a product

## Status

Accepted — 4 September 2026. Widens [WA001](WA001-english-language-documentation.md), which stays in force.

## Context

WA001 settled the language of `documentation/`, `.cursor/rules/` and the ADRs. It said nothing about
everything else the project writes, and the gap filled itself with French: pull request titles and
bodies, issue and review comments, commit messages, prise files, journal entries.

That was tenable while the repository was read by one person. It stopped being tenable the moment
the work became public. **A contributor who lands on a pull request cannot review a change whose
justification they cannot read** — and the justification is the part that matters here, because this
project routinely writes down *why* a fix takes the shape it takes. On 3 September 2026 a
cross-review of the seven open pull requests produced findings whose value was entirely in their
argument; every one of those arguments was in French.

There is also a second, quieter cost. The repository already contains English documentation that
cites French artefacts — an `UPGRADE.md` note pointing at a French pull request, an ADR referencing a
French journal entry. A reader following those links leaves the language they were reading in,
mid-sentence.

## Agreement

**English is the working language of this project.** It applies to everything the project writes,
whether it lives in the repository or on GitHub:

| Surface | Language |
|---|---|
| Pull request titles and bodies | English |
| Issue titles and bodies | English |
| Comments — on issues, on pull requests, inline review comments | English |
| Review summaries and approvals | English |
| Commit messages, including trailers | English |
| Prise files under `.worktrees/prises/` | English |
| Journal entries under `documentation/journal/` | English (already required by WA001) |
| ADRs, WAs, OSTs, PRDs, OpenSpec changes | English (already required by WA001) |
| Code identifiers, docblocks, comments in `src/` and `tests/` | English |
| Release notes and tags | English |

### The one exception

**The French translations of the user documentation.** The `*.fr.md` files under
`documentation/user/`, and the French canvases and layouts that render them, are **a product for
readers** — not a working language. They stay French, they stay maintained in parity with their
English counterpart, and nothing in this agreement invites translating them away.

The distinction that decides any future case is this: *is this text addressed to a user of Durable,
in a language we chose to serve them in?* If yes, it follows the product. If it is addressed to
whoever works on Durable — a reviewer, a maintainer, our future selves — it is English.

Two consequences worth stating, because they are the cases that will come up:

- A **French user page has an English source of record.** When the two disagree, the English page is
  the one that is right, and the French one is a bug to fix — that is what parity means.
- **Third-party quotations** stay in their original language, as WA001 already allows.

## What this does not do

**It does not rewrite history.** Commit messages already written stay as they are: rewriting them
would mean rewriting every merged branch, and the value of a readable log does not survive
invalidating every commit reference in every issue, ADR and journal entry that cites one.

**It is not a mandate to bulk-translate.** Existing French text in scope is debt, and it is listed
below so that it is visible rather than silent. It gets translated when the file is next edited for
another reason, or in a change that says so.

### Known debt, on the day this was written

| Where | Extent |
|---|---|
| `documentation/audit/` | 22 files, French, landed by #271 — and unknown to `INDEX.md`, `LIFECYCLE.md` and `HUGO.md` |
| `documentation/journal/inbox/` | 8 entries, French, in a directory WA001 already required to be English |
| `.worktrees/prises/` | 18 open prise files — 17 French, plus this branch's, which is not — and `PRISES.md` itself |
| Commit history | French throughout; frozen by the paragraph above |

The pull requests, issues and comments dated 3 September 2026 and later were translated on
4 September 2026, before this agreement was written. They are the starting point, not part of the
debt.

## Consequences

- Reviews may reject a non-English addition to any surface in the table above, the same way WA001
  allows it for documentation.
- An agent working on this repository writes English by default, and asks before writing anything
  else. The single exception is narrow enough to name explicitly when it applies.
- `documentation/audit/` is the first thing this agreement judges, and it judges it
  non-conforming — in language and in placement. Deciding what happens to it is a follow-up, not a
  silent grandfathering.
- Nothing here is enforced by CI today. A guard is possible — a check on the language of a pull
  request body, or on new files under the paths above — and it is deliberately not part of this
  agreement: the rule is worth stating before it is worth automating, and WA005 is the reminder that
  an unenforced rule is still a rule people can follow.

## References

- [WA001 — English language for project documentation](WA001-english-language-documentation.md)
- [documentation/LIFECYCLE.md](../LIFECYCLE.md)
- [documentation/INDEX.md](../INDEX.md)
