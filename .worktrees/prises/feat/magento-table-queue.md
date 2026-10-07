# feat/magento-table-queue

- **Chantier** : #731, `durable_queue` on Magento's adapter (enqueue with `available_at`, take with SKIP LOCKED and a lease, ack, redelivery); stacked on #958 (schema) on #948 (connection); draft PR
- **Entrées** : `src/DurableModule/Runtime/TableQueue/`, unit and integration tests under `tests/*/DurableModule/`
- **État** : en cours
