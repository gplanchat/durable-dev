# feat/grpc-client-extraction

- **Scope**: extract the gRPC unary client out of the Temporal bridge into its own package, gplanchat/grpc-client (src/GrpcClient).
- **Entries**: src/GrpcClient (new), src/Bridge/Temporal (Grpc, Http, TemporalConnection), the three composer.json, tests, UPGRADE.
- **State**: in progress.
