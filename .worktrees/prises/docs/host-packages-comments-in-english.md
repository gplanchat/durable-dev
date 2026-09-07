# docs/host-packages-comments-in-english

- **Work item**: the third comment slice, after `docs/core-comments-in-english` (#292) and
  `docs/bridge-comments-in-english` (#293). This one takes the host integration packages, one
  commit group per package.
- **Entry points**: `src/DurableModule/**` except `view/adminhtml/**`, `src/DurableLaravel/**`,
  `src/DurablePlugin/**` except `Resources/views/**` and `tests/**`, `src/DurablePhpstan/**`
  except `Reflection/StubMethodsExtension.php`, `src/DurableRector/**`. The exclusions are the
  files `fix/ui-strings-in-english` (#287) is editing; they come back once that merges.
  `src/DurableBundle` is held for the same reason — #287 touches seven of its files.
  Comments and docblocks only.
- **State**: in progress.
