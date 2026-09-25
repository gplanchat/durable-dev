# refactor/extension-loaders

- **Scope**: #342. C0–C13 are merged in #543: `DurableExtension`'s registrations moved into
  `@internal` loader classes under `DependencyInjection/Loader/`, the container snapshot pinned
  each move, two inconsistencies were fixed, and definitions moved to `durable.*` ids with their
  class ids kept as aliases. Left: C14, which decides whether the public class ids stay public
  through beta1 or become private with an UPGRADE entry.
- **Entries**: `src/DurableBundle/DependencyInjection/`, `tests/unit/DurableBundle/DependencyInjection/`,
  `UPGRADE.md`.
- **State**: C0–C13 merged (#543). C14 waits for the user's decision; nobody is working on it.
  Held by vera so that C14 lands on this snapshot.
