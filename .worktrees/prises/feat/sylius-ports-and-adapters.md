# feat/sylius-ports-and-adapters

- **Work item**: `use-cases/nexus-demo.md` and `documentation/blog/nexus-bounded-contexts.md` both
  say the port-and-adapter arrangement they recommend is **not** in the repository. This makes it
  real in the shop: a `Payments` port in the shop's language, a `NexusPayments` driven adapter that
  calls the stub and translates, value objects with factories at the boundary, and a `PlaceOrder`
  use case that knows none of it.
- **Entry points**: `sylius/src/Application/**`, `sylius/src/Infrastructure/Nexus/**`,
  `sylius/src/Durable/Workflow/OrderWorkflow.php`, `sylius/tests/Unit/**`, and one line of
  `.github/workflows/ci.yml`. Five commits, each under 200 lines: characterisation, value objects,
  port and adapter, use case and workflow, CI.
- **Measured before designing**: `WorkflowEnvironment` is `final`, so an adapter that holds one
  cannot be faked; and the in-memory harness **refuses** Nexus by design
  (`NexusHarnessFailsFastTest`), so the workflow cannot run under it. The translation therefore
  lives in `fromWire()` factories on the value objects, which are pure and testable, and the
  adapter keeps only the `await` plumbing.
- **Careful**: the demonstration's observable behaviour is a contract. Two Nexus operations,
  `verify` then `charge`, the early return when `accepted` is false, and the result shape
  `['verified' => …, 'charge' => …]` that `DemoNexusBillingCommand` prints and `demo/README.md`
  documents. The value objects therefore carry `toWire()` as well.
- **`done_when`**: the two sentences claiming the shape is absent are retired from the guide and
  the post, and the shop's unit suite runs in CI.
- **State**: in review — PR #300.
