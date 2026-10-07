# feat/magento-admin-sql-catalog

- **Chantier** : #737 (epic #740): the Magento admin grid and run page on the Magento-adapter run catalogue (resource/durable). Stacked on feat/magento-runtime-factory-sql (#1003), itself on the throwaway feat/magento-sql-integration (#1002).
- **Entrées** : `src/DurableModule/Ui/DataProvider/`, `src/DurableModule/Block/Adminhtml/`, `src/DurableModule/view/adminhtml/`, `tests/unit/DurableModule/`, `magento/tests/`.
- **Overlap** : #818 (waiting for a worker) reads `picked_up_at` through this catalogue and is not built here; feat/magento-admin-nexus-in-flight touches the same run page.
- **État** : en cours
