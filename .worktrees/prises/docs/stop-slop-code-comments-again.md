# docs/stop-slop-code-comments-again

- **Work item**: finish the stop-slop pass where #286 stopped. It cleared `documentation/user/`
  and the home page; the em dash is still house punctuation everywhere else. Counted on `main`
  after the six comment slices landed: **682** in `src/`, **270** in `tests/`, **81** in
  `CLAUDE.md` and `documentation/wa/`. 1,033 in all.
- **Why it is re-cut rather than resumed**: #297 did this work and was closed. It removed em dashes
  from French comments, and those comments are English since #292, #293, #294, #296, #299 and #301,
  often reworded. 326 of its 564 files conflicted. Resolving would mean arbitrating between two
  versions of one sentence, 326 times, to apply a punctuation rule.
- **Entry points**: `src/**`, `tests/**`, `CLAUDE.md`, `documentation/wa/**`. Not
  `src/Bridge/Temporal/{Api,Generated}` — generated. Not `documentation/adr/` — an ADR records what
  was decided when it was written.
- **Method, from #286's prise**: each one judged in place, never substituted. A colon where the
  clause explains, a comma where it qualifies, parentheses around an aside, a full stop where two
  sentences were hiding in one. Two survive on purpose in the Nexus pages, inside a block quoting
  `NexusHandlerPass` verbatim, and any string a test asserts on is left alone.
- **Careful**: this touches every package, so it conflicts with anything else in flight. It goes
  last, after the alpha, when the open PRs have landed. And `.github/workflows/` is in scope only
  if a human asks for it: #297 touched six workflow files, which CLAUDE.md says to ask about.
- **State**: not started.
