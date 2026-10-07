# feat/magento-resume-lock

- **Chantier**: #732: resume lock on the Magento adapter, a TTL row and GET_LOCK on the journal's connection, both built behind one port and measured.
- **Entrées**: `src/DurableModule/Runtime/`, `tests/unit/DurableModule/`, `tests/integration/` (private MySQL 8.4).
- **Overlap**: #746 (durable:setup schema, another worker) will own the lock table if it lands first; stacked on #948 (feat/magento-durable-connection).
- **État**: en cours
