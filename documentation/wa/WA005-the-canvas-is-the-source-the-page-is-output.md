# WA005 — The canvas is the source, `layouts/index.html` is output

## Status

Accepted — the rule holds today; the CI guard that enforces it is the follow-up named below.

## Context

The landing page has two authors. It is designed in a Claude Design canvas and turned into a Hugo
template by `hugo-docs/import-design.py`; it is also edited directly in the repository, because a
correction is faster to make where the file already is.

**That has now cost the same thing three times in one night.** The "coming soon" wording, the
default backend, and a fix stopping the picker from offering unpublished packages were each written
into `layouts/index.html`, and each was lost — twice to a regeneration that did not know about them,
once to a rebase. The third loss happened *inside the hour* after a pull request comment predicted
it in as many words.

The pattern is not carelessness. It is that **two sources of truth for one file cannot both be
right**, and nothing in the repository said which one was.

## Decision

**The canvas is the source. `hugo-docs/layouts/index.html` is output, and nobody edits it by hand.**

A correction to the page — text, chip state, default selection, a logo, a package name — is made in
the canvas and re-imported. Slower for a one-word change, and that is the trade: a regeneration
stops being an event to fear, because there is nothing in the output that the source does not have.

### What that requires, and it is not free

The rule is unenforceable while the canvas lives outside the repository. Making it real means:

1. **Committing the canvas source** — the `.dc.html` file — next to the script that reads it.
2. **A CI check** that re-runs `import-design.py` on the committed source and fails if the result
   differs from the committed `layouts/index.html`. A hand edit then shows up as a red build with
   the diff attached, which is the whole point: the loss becomes visible *before* the regeneration
   rather than after it.

Until (1) and (2) exist, this working agreement is a convention. It is written down anyway, because
three losses in one night were three people each reasonably believing they were doing the normal
thing.

### What stays hand-written

Everything that is not the landing page. `assets/logos/*.svg` are inputs to the import, not output —
they are edited in the repository and the import inlines them. `import-design.py` itself, its
guards, and `layout-head.html` are ordinary code.

## Consequences

- **A one-word fix to the page becomes a canvas round trip.** This is the cost, and it is real. It
  is smaller than the cost of losing the fix, which is what happens today.
- **The canvas enters version control**, which it has never been in. Its history becomes reviewable,
  and a regeneration becomes a diff of two committed things rather than an import from somewhere
  else.
- **`check_packages_resolve()` keeps its job.** It catches a class the diff cannot: a command the
  picker can build that names a package nobody publishes. Structural agreement and semantic
  correctness are different guards.
- **This agreement is falsifiable.** If the canvas cannot be committed — a format that does not
  survive git, a tool that will not read a file from disk — then decision (1) fails and the honest
  answer is the other branch: retire `import-design.py` and let the page live in git like the rest
  of the site. That is stated here so the fallback is a decision rather than a drift.

## References

- [WA001 — English language for project documentation](WA001-english-language-documentation.md)
- `hugo-docs/import-design.py` — the import, and the guards it already carries
- [documentation/HUGO.md](../HUGO.md)
