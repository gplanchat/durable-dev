# PRD003 — `DurableTestCase` test base

## Objective

Centralize test utilities for durable execution (workflows, activities, in-memory runtime, and simulated distributed scenarios).

## Delivered behavior

- **`DurableTestCase`** provides:
  - lazy setup: `stack()`, `eventStore()`, `runtime()`, `activityExecutor()`, `executionId()`
  - event type order assertions: `assertEventTypesOrder`, `assertEventTypesOrderOn`
  - draining: `drainActivityQueueOnce`, `runUntilIdle`
  - distributed log: `assertDistributedWorkflowJournalEquivalent`
  - activity queue: `assertActivityTransportPendingEquals`

- **Former trait** `UsesInMemoryDurableStack`: removed; everything merged into `DurableTestCase` (see ADR003).

Test classes generally declare **`#[CoversClass(…)]`** (and **`#[CoversFunction(…)]`** when relevant) for coverage, rather than `#[CoversNothing]`.

## References

- `tests/Support/DurableTestCase.php`
- [ADR003 — PHPUnit standards](../adr/ADR003-phpunit-testing-standards.md)
- [PRD002 — In-flight workflow scenarios](PRD002-in-flight-workflow-scenarios.md)
