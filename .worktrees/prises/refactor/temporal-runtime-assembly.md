# refactor/temporal-runtime-assembly

- **Scope**: #356, PR1 only (the assembly, no BC). One `Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly`
  builds the Temporal graph (history cursor, run catalog, workflow task processor, workflow client,
  read-through store, RPCs, scratch activity worker) from `(client, connection, registry, loader)`;
  Laravel, Magento and Symfony take their services from it. Magento gets one client per request
  and passes the loader. PR2 (Magento on the read-through store, deletions) waits for the user's
  decisions.
- **Entries**: `src/Bridge/Temporal/TemporalRuntimeAssembly.php`, `src/DurableLaravel/DurableServiceProvider.php`,
  `src/DurableModule/Runtime/RuntimeFactory.php`, `src/DurableBundle/DependencyInjection/DurableExtension.php`
  (Temporal block only, after #485), their tests.
- **Stacked on #510** (`fix/temporal-heartbeat-on-every-host`), itself on #493. The Symfony commit
  waits for #485.
- **State**: PR1 in review, #532, based on #531 (dave OK on C1–C4). PR2 (Magento read-through store, deletions) parked on the user's decisions — arwen.
