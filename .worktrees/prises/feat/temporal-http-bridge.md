# feat/temporal-http-bridge

- **Chantier** : Temporal bridge without ext-grpc — new `gplanchat/durable-bridge-temporal-http` package (gRPC over curl/HTTP2 and the JSON gateway on 7243), selected by `transport=` on the `temporal://` DSN.
- **Entrées** : `WorkflowServiceClientInterface` in the Temporal bridge, `GrpcUnary` call sites swept, `src/Bridge/TemporalHttp/`, unit + integration tests.
- **État** : en relecture — PR #392
