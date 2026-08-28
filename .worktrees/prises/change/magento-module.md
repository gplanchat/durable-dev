# change/magento-module

Worktree : `.claude/worktrees/magento`. PR #157 fusionnée — 1.1, 1.2, 1.4, 2.1,
2.2, 3.2 sont livrées.

Tranche en cours : **1.3** — ce qu'un consommateur mourant laisse derrière lui.
Le banc n'a pas d'AMQP (`compose.yaml` : MySQL, OpenSearch, Redis, Temporal),
donc c'est la file en base : `Magento\MysqlMq`. Redélivrance, lettre morte ou
silence, c'est à mesurer, pas à déduire de la documentation d'AMQP.

L'instrument est un sujet de sonde dans le module — topic, publisher, topology,
consumer, et un gestionnaire qu'on peut faire traîner assez longtemps pour le
tuer au milieu d'un message. C'est la plus petite instance de ce que la 4.1
écrira en vrai, pas du jetable.
