# docs/bundle-comments-in-english

- **Work item**: the last comment slice. #292, #293, #294, #296 and #299 each excluded the files
  `fix/ui-strings-in-english` (#287) was editing; this one takes exactly those, and closes the
  sweep for `src/` and `tests/`.
- **Entry points**: all of `src/DurableBundle/**`, `src/Durable/Testing/**`,
  `src/DurablePlugin/{Resources,tests}/**`, `src/DurablePhpstan/Reflection/StubMethodsExtension.php`,
  and `tests/unit/DurableBundle/DependencyInjection/DurableTemporalWithoutJournalTest.php`.
  `src/DurableModule/view/**` needs nothing — #287 already left it clean.
- **Branched off #287, not off main**, so the two do not conflict; the PR targets
  `fix/ui-strings-in-english` and GitHub retargets it to `main` when #287 merges.
- **State**: in progress.
