# PHPUnit standards

ADR003-phpunit-testing-standards
===

Introduction
---

This **Architecture Decision Record** defines the mandatory standards for writing PHPUnit tests in the Durable project. These standards favor maintainable, reliable, isolated tests with minimal use of PHPUnit mocks.

Testing philosophy
---

Tests **MUST** prefer real implementations over mocks. This leads to:

- More realistic test scenarios
- Better integration coverage
- Less brittle tests
- Easier refactoring

Rule: minimize PHPUnit mocks
---

Tests **MUST** reduce to a minimum the use of PHPUnit-provided mocks (`createMock`, `createStub`, `getMockBuilder`, etc.).

### Recommended alternatives

1. **Real implementations**: use real services when possible
2. **In-memory implementations**: e.g. `InMemoryEventStore`, `InMemoryActivityTransport`
3. **Dedicated test doubles**: classes implementing interfaces, in separate files
4. **Symfony components**: `MockHttpClient`, `MockResponse` for HTTP tests

### Exceptions

PHPUnit mocks **MAY** be used only for:

- Error conditions that are hard to reproduce
- External services unavailable in tests
- Verifying specific interactions when strictly necessary

Activities and workflows
---

For activity tests:

- Register real handlers via `RegistryActivityExecutor`
- Use `InMemoryEventStore` and `InMemoryActivityTransport` for unit tests
- For Messenger integration tests, use Symfony test transports

### `DurableTestCase` (in-memory stack)

The full in-memory test stack (event store, transport, `ExecutionRuntime`, `stack()` / `executionId()` helpers, assertions on the distributed log and activity queue) lives in **`integration\Gplanchat\Durable\Support\DurableTestCase`**. Workflow / durable tests **extend** this base class; there is no dedicated trait.

Example
---

```php
final class WorkflowTest extends TestCase
{
    public function testWorkflowCompletesWithActivityResult(): void
    {
        $eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $executor->register('echo', fn (array $p) => $p['msg'] ?? '');
        $runtime = new ExecutionRuntime($eventStore, $transport, $executor);
        $engine = new ExecutionEngine($eventStore, $runtime);

        $result = $engine->start((string) Uuid::v7(), function (ExecutionContext $ctx, ExecutionRuntime $rt) {
            return $env->await($env->activity('echo', ['msg' => 'hello']));
        });

        self::assertSame('hello', $result);
    }
}
```

PHPUnit metadata (PHPUnit 11 → 12)
---

Docblock annotations (`@test`, `@covers`, `@group`, etc.) are **deprecated** in PHPUnit 11 and will be removed in PHPUnit 12. The project uses **PHP 8 attributes** from the `PHPUnit\Framework\Attributes` namespace (e.g. `#[Test]`, `#[Group('…')]`).

### Code coverage (metadata)

- Prefer **`#[CoversClass(ClassName::class)]`** (repeatable) for the main code under test.
- The public workflow API is **`WorkflowEnvironment`**; workflows receive `$env` and call `$env->await()`, `$env->activityStub()`, etc.
- Avoid **`#[CoversNothing]`** except in exceptional cases (purely infra tests with no SUT in `src/`).

### Test code style

- Composer scripts: `composer cs` (fix), `composer cs:check` (dry-run), `composer test`, `composer test:coverage`. See ADR002.

### Upgrade to PHPUnit 12

- Project checklist: [OST002 — PHPUnit 12](../ost/OST002-phpunit12-upgrade-checklist.md).

CI (GitHub Actions)
---

- Workflow: `.github/workflows/ci.yml`.
- **PHP**: matrix **8.2** and **8.3**; `qa` job runs `composer cs:check` then `composer test` (`phpunit --strict-coverage`).
- **Coverage**: separate job on **PHP 8.2** with **PCOV** (`pcov.directory=.`), then `composer test:coverage` (text report, filter `src/`).
- **Local**: `composer test:coverage` requires **PCOV** or **Xdebug**; otherwise PHPUnit emits a warning (Composer suggests `ext-pcov`). See [PRD004](../prd/PRD004-ci-github-actions.md).
- **Minimum line threshold**: not enforced for now (report visible in CI logs); may add later if a stable PHPUnit option or tool is chosen.

References
---

- [PHPUnit Test Doubles](https://docs.phpunit.de/en/10.5/test-doubles.html)
- [Symfony HTTP Client Testing](https://symfony.com/doc/current/http_client.html#testing-request-data)
- [ADR001 - ADR process](ADR001-adr-management-process.md)
