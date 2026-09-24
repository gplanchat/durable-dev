# chore/bench-skeleton-residue

- **Scope**: #380. Delete the Laravel skeleton residue the bench never uses (example tests and `phpunit.xml`, `inspire`, the `User` model, factory and seeder, the Vite front end and welcome page), the empty-folder placeholders in `symfony/`, and the dead `../../durable-symfony-vendor` candidate in the Symfony bench autoload helpers.
- **Entries**: `laravel/`, `symfony/src/**/.gitignore`, `symfony/load_autoload_runtime.php`, `symfony/bin/phpunit`, `symfony/tests/bootstrap.php`, `sylius/tests/.gitignore`.
- **State**: in progress (durable-48, lane D).
