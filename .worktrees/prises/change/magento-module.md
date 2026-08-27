# change/magento-module

Worktree : `.claude/worktrees/magento` (déplacé depuis le scratchpad d'une
session morte). PR **#157**, verte, en attente de fusion.

Livré depuis la reprise :

- **CI remise au vert** — deux causes sans rapport avec la branche : le style
  PER-CS3.0 sur les quatre fichiers du module, et deux tests d'intégration
  Temporal construisant `TemporalStartingEventStore` (classe retirée par
  DUR002/DUR019), que le merge de `main` supprime.
- **Tranche 1.4** — le verrou *est* partagé entre processus. Mesuré à deux
  processus par `magento/probe-lock.php`, gardé au dépôt. Deux trouvailles qui
  changent la 2.3 : le conteneur rend une `Lock\Proxy` qui ne nomme rien, et
  `Backend\Database::lock()` rend `true` sans verrouiller quand
  `isDbAvailable()` est faux.

Prochaine tranche : **1.3** (ce qu'un consommateur mourant laisse) ou **1.5**
(long-poll). Note pour la 1.3 : pas de RabbitMQ dans `compose.yaml`, donc file
en base — la redélivrance n'a pas les règles d'AMQP.
