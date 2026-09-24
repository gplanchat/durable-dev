# fix/grpc-transport-honesty

- **Scope**: #353 (R-9, R-10, M21, M22, M43). Bounded retry with jitter on gRPC codes 4, 8, 14
  for every `GrpcTransport`, errors thrown as Messenger's `TransportException`; a
  `ReceiveOnlyTransport` trait for the three Temporal receivers with a setup warning when
  `retry_strategy`/`failure_transport` would be ignored; unknown DSN keys refused, TLS
  `ca`/`cert`/`key` and `api_key` in the DSN.
- **Entries**: `src/Bridge/Temporal/Grpc/`, `src/Bridge/Temporal/Http/{GrpcWire,CurlGrpcTransport,GuzzleGrpcTransport}.php`,
  `src/Bridge/Temporal/WorkflowServiceClientFactory.php`, `src/Bridge/Temporal/TemporalConnection.php`,
  `src/Bridge/Temporal/Messenger/`, `src/Bridge/Temporal/README.md`, their tests. The setup warning
  touches `src/DurableBundle/DependencyInjection/Compiler/`, shared with #334: coordinated with
  alice before that slice.
- **Not in scope**: the TLS Temporal in the integration job needs `.github/workflows/`, a human edit.
- **State**: slice A merged (#512). Slice B in review, PR #516 (sabrina and dave OK). Slice C waits for #485 — arwen.
