# docs/use-cases-bounded-contexts

- **Work item**: the DDD / hexagonal reading of the Nexus demonstration, as a page of the
  `use-cases/` section. Graça's Explicit Architecture forbids the synchronous cross-context call
  because it couples uptime; a durable call removes that reason and none of the others. Carries the
  context map table (four namespaces, three endpoints), the stub as a driven adapter rather than a
  port, the shared kernel, the nine-second budget as the line between a cross-boundary query and a
  saga step, and the correlation-ownership test against events.
- **Entry points**: `documentation/user/use-cases/` only. Stacked on `docs/section-use-cases`
  (PR #265), which is not merged. Cites `nexus-demo` for the measurements rather than repeating
  them. No source change.
- **State**: in progress.
