# docs/tests-comments-in-english

- **Work item**: the fourth comment slice, after `docs/core-comments-in-english` (#292),
  `docs/bridge-comments-in-english` (#293) and `docs/host-packages-comments-in-english` (#294).
  This one takes `tests/` — 161 files, the largest remaining block. A test's comments are what say
  which failure mode it exists to catch, so they are worth the same care as a docblock.
- **Entry points**: `tests/unit/**` and `tests/integration/**`. Comments and docblocks only, plus
  the French assertion messages a failing run prints — those are read by whoever the run goes red
  on. Test method names are already English. French **fixture** strings stay: several tests use
  French text on purpose, to prove a payload survives a round trip byte for byte.
  Excluded: `tests/unit/DurableBundle/DependencyInjection/DurableTemporalWithoutJournalTest.php`
  and the three test files `fix/ui-strings-in-english` (#287) adds; they come back once it merges.
- **State**: in progress.
