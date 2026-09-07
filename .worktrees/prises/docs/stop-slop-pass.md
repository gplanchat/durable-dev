# docs/stop-slop-pass

- **Work**: a stop-slop pass over the user guide and the home page. The corpus was already free
  of the usual AI tells (0 throat-clearing openers, 0 "not X, it's Y", 0 slop vocabulary), so the
  whole pass is the em dash, which the skill forbids and which this repo used as house
  punctuation: 344 in `documentation/user/*.md`, 68 in the home page. Plus a handful of real
  defects: `simply` x5, two rhetorical "What ... is" setups.
- **Scope**: `documentation/user/**/*.md` (English **and** French mirrors, so a translation never
  diverges from its source) and the home page's design sources
  `hugo-docs/variant-b-narrative*.dc.html` + `layout-head.html`, regenerated into
  `hugo-docs/layouts/index*.html`. Nothing under `src/`, no ADR.
- **Decision**: the human chose "strip every em dash" and "mirror the French" over keeping the
  house style. Each one judged in place: comma, colon, semicolon, full stop, parentheses or a
  recast sentence, never a mechanical substitution. Two survive on purpose, in the Nexus pages,
  where the fenced block quotes `NexusHandlerPass` verbatim.
- **Careful**: renaming a heading retires its published URL fragment. The 35 affected headings
  carry an explicit `{#old-slug}` so external deep links keep working.
- **State**: in review, PR #286.
