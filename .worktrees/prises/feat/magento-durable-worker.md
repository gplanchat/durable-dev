# feat/magento-durable-worker

- **Chantier** : #736, `durable:worker` on the Magento SQL backend (`--role=database`-less: drains the resume, activity and timer queues of `TableQueue`; transient errors re-queued, poison acked); stacked on #1003 on #1002; draft PR
- **Entrées** : `src/DurableModule/Console/Command/RunWorkerCommand.php`, `src/DurableModule/Runtime/`, `magento/tests/`, `tests/unit/DurableModule/`
- **État** : en cours
