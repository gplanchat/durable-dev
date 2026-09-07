# fix/nexus-stub-fixture-in-english

- **Work item**: the last French identifiers in core test code. `NexusStubTest` declares a
  `FacturationContract` / `FacturationServed` pair with `verifier` and `encaisser`, and the PHPStan
  fixture `StubCallSites` declares its own `Facturation` with the same operations, a `bareme()`
  without an attribute and an `encasser` typo. #290 renamed the demonstration; these two files were
  outside that slice and kept the names it retired.
- **Entry points**: `tests/unit/Durable/Nexus/NexusStubTest.php`,
  `tests/unit/DurablePhpstan/Fixtures/StubCallSites.php` and the assertions of
  `tests/unit/DurablePhpstan/StubMethodsExtensionTest.php` that name those operations. Identifiers,
  service and operation names, payload keys and the endpoint. French comments and assertion
  messages stay: they belong to the `tests/` comment slice that follows
  `docs/core-comments-in-english` and `docs/bridge-comments-in-english`.
- **Careful**: two fixture methods exist to be wrong. `bareme()` carries no attribute and
  `encasser` is a typo, and the extension's tests assert that both are still reported. Their
  English names have to stay wrong in the same way, and the typo has to differ from the activity
  fixture's `chrage`.
- **State**: in progress.
