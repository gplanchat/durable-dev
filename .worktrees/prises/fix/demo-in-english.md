# fix/demo-in-english

- **Work item**: the demonstration speaks French all the way down — not only its prose but its
  identifiers. `LivraisonContract::planifier()`, `ExpedierWorkflow`, `CommandeNexusWorkflow`,
  `$commande`, `$creneau`, `demo/lancer.sh --arreter`. WA006 makes English the working language of
  everything this repository ships, and a demonstration is the first code a reader meets.
- **Entry points**: `src/DurableDemoContracts/**` and `tests/unit/DurableDemoContracts/`,
  `laravel/app/Durable/**` and `laravel/probe-nexus.php`, `sylius/src/Durable/**` and
  `sylius/config/services.yaml`, `symfony/src/{Durable,Samples,Command}/**`,
  `magento/app/code/Gplanchat/DurableProbe/**` and `magento/probe-*.php`, `demo/lancer.sh`,
  `bin/demo-nexus`, `demo/README.md`.
  The package is not published (`src/DurableDemoContracts/composer.json` says so, and it is absent
  from `SPLITS` in `bin/splitsh-publish.sh`), so the renames break nobody's build. What must not
  move: the Magento module name, the `durable:demo` command and the strings CI greps for
  (`notify:charge:ORD-4242`, `durable.demo.charge`).
- **State**: in progress.
