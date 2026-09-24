# fix/receive-only-temporal-transports

- **Scope**: #353, slice C (M21). A `ReceiveOnlyTransport` trait replaces the three copies of the
  `send()`/`ack()`/`reject()` stubs on the Temporal receivers; `messenger:consume` warns when it is
  given an option these receivers never honour (`--limit`, `--failure-limit`), naming the ones that
  work; the bridge README says what applies to them and what does not.
- **Entries**: `src/Bridge/Temporal/Messenger/`, `src/Bridge/Temporal/README.md`, a console listener
  under `src/DurableBundle/`, their tests.
- **Not in scope**: the TLS Temporal in the integration job (a `.github/workflows/` change, the user's).
- **State**: code complete and pushed (trait, README, warning listener), stacked on vera's #536 for `DurableWorkerInspection`. PR held until #536 merges (then against `main`) and the user decides on adding `psr/log` to the bundle's `composer.json` — arwen.
