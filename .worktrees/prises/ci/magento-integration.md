# ci/magento-integration

- **Scope**: #738 run the Magento adapter integration tests (`phpunit.magento.xml`, suite `magento-integration`) in the `magento-boot` job, against a private database on its MySQL service; a skip fails the job.
- **Entries**: .github/workflows/ci.yml (supervised, the project owner asked for exactly this change).
- **State**: in progress.
