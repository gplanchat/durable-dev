# feat/magento-run-projection

- **Chantier**: #733: the run projection on the Magento adapter (start, pickup, wait, outcome), `waiting_on` cleared when a run ends.
- **Entrées**: `src/DurableModule/Store/`, `tests/integration/DurableModule/` (private MySQL 8.4).
- **Overlap**: stacked on #958 (feat/magento-durable-schema, the schema), itself on #948; the DBAL counterpart of the fix is #868.
- **État**: en cours
