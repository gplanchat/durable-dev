# refactor/symfony-bench-dashboard-on-observation

- **Scope**: #359, part B. The Symfony bench dashboard reads `Gplanchat\Durable\Observation` (`RunDashboard`, `RunTimeline`) like the Sylius plugin and Magento, instead of the 853-line `TemporalEventsDashboardDataProvider` (owner decision, 2026-09-24: port it in #359).
- **Entries**: `symfony/src/Dashboard/`, `symfony/src/Controller/DashboardController.php`, `symfony/templates/dashboard/`, `symfony/config/services.yaml`, `symfony/tests/`.
- **State**: in progress (durable-48, lane D).
