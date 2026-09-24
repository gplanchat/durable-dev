# fix/symfony-bench-run-is-a-post

- **Scope**: #359, part A. Starting a sample run is a POST refused cross-site, the README says the bench is local-only, the smoke tests follow, and the stale generated `config/reference.php` goes. Doctrine is left to #393 (owner decision, 2026-09-24); the dashboard port is part B.
- **Entries**: `symfony/src/Controller/SamplesWorkflowController.php`, `symfony/templates/samples/`, `symfony/tests/Http/SamplesControllerSmokeTest.php`, `symfony/README.md`, `symfony/config/reference.php`, `symfony/.gitignore`.
- **State**: in review — PR #492 (durable-48, lane D).
