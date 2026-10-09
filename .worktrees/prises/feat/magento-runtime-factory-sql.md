# feat/magento-runtime-factory-sql

- **Scope**: #754: RuntimeFactory assembles the Magento SQL backend (resource/durable), no Nexus, timers as FireWorkflowTimersMessage. Stacked on feat/magento-sql-integration (#1002), a throwaway merge of the #740 store PRs.
- **Entries**: `src/DurableModule/Runtime/` (RuntimeFactory, DatabaseBackend, TableQueue adapters), `src/DurableModule/etc/di.xml`, `tests/unit/DurableModule/`, `magento/tests/`.
- **Overlap**: #736 (durable:worker on SQL) builds on DatabaseBackend; #737 reads the catalogue.
- **State**: en cours
