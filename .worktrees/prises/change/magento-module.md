# change/magento-module

Worktree : `.claude/worktrees/magento`. PR **#175** — tranche **3.1**, verte
(22/22), `CLEAN / MERGEABLE`, en attente de fusion. Tâche 1 finie (#168, #170) ;
2.1, 2.2, 2.3 (#172), 3.1, 3.2.

3.1 : `di.xml` porte deux tableaux — les classes de workflow et les objets
gestionnaires d'activités. **Le contrat ne se déclare pas** : la fabrique lit
les interfaces du gestionnaire et garde celles qui portent `#[ActivityMethod]`.
Les noms d'activité viennent donc des attributs, et les trois fermetures
écrites à la main dans la commande de démo ont disparu.

**Le refus est le mécanisme.** `MagentoRuntime::run()` enregistrait au vol un
workflow inconnu, ce qui rendait la déclaration vide de sens et laissait le
`Scenario: An undeclared workflow fails at the moment of the mistake` faux
depuis la 3.2. Il lève maintenant, en nommant la classe et l'argument de
`di.xml`.

⚠ **Un point attend l'avis de l'auteur sur la #175** :
`PayloadToContractMethodInvoker` est descendu de `durable-bundle` à `durable`
parce que deux hôtes en ont besoin et qu'il n'importe rien de Symfony.
`BREAKING CHANGE` traversant deux paquets — son nom pleinement qualifié est
écrit dans le conteneur **compilé** des consommateurs. Le garder dans le bundle
et faire porter au module ses ~30 lignes reste possible.

**Décisions de l'auteur du 28/08 :**
- on suit **l'ordre du change** — tâche 4, puis tâche 5. La 4 n'est pourtant pas
  sur le chemin critique de Nexus (Nexus passe par le cluster Temporal, donc par
  la 5) ; c'est un choix assumé.
- le **tableau de bord admin** est un change à part, après la 5. Le README du
  banc en décrit déjà la forme — route `/admin/durable_dashboard/dashboard/index`,
  champ « Temporal DSN » — pour un module qui n'a ni contrôleur ni `adminhtml` :
  c'est un cahier des charges, pas un état.
- Magento ne sera compatible qu'avec `memory` et `temporal`, **définitivement**.
  Le journal SQL sur `ResourceConnection` n'est pas reporté, il n'est pas prévu.

**Servir le banc en HTTP, mesuré :** `php -S 127.0.0.1:8080 -t pub/ phpserver/router.php`
depuis `magento/` — boutique HTTP 200 (1,0 s), admin HTTP 200 (0,4 s), utilisateur
`admin`. L'URL de base est déjà réglée sur ce port. Rien à construire.

**Sync avec chantier-nexus (28/08) :** leur chantier est à 28/31, pas 12.
`TemporalNexusWorker` poll, route et répond, immédiat comme différé, annulation
comprise. Trois choses pour nous : ne pas écrire de code Nexus tant que la surface
n'est pas stabilisée (elle passe à un contrat typé) ; tout passe par le cluster
Temporal des deux côtés, donc la tâche 5 est le préalable absolu ; et **ils n'ont
jamais fait tourner deux processus OS** — Magento serait leur premier, ce qui lève
leur plus grosse hypothèse non vérifiée. Le worker n'a que trois arguments et
aucune dépendance framework : côté Magento on l'instancie et on boucle sur
`pollOnce()`. **Nouveau (28/08)** : `NexusOperationRegistry` se construit
désormais par `routedBy('temporal')` ou `unavailableOn('<backend>')`, et le
second refuse à `register()` — la tâche 5 devra le construire selon le backend
assemblé, et rien d'autre à écrire pour bénéficier de la garde. Un endpoint se crée à la main
(`temporal operator nexus endpoint create`), et une file que personne ne poll est
un endpoint qui ne répond jamais, sans erreur nulle part.

Tranche **en cours** : la **procédure de migration de la rupture de #175**.
`PayloadToContractMethodInvoker` est descendu de `durable-bundle` à `durable`
sans procédure, ce que la règle arbitrée interdit — Rector d'abord, script
sinon, documentation dans tous les cas. Un set `durable-upgrade.php` à côté de
`temporal-sdk.php`, testé par fixture comme les quatre règles existantes.
⚠ La session `splitsh-integration-alpha8` déplace elle aussi une classe de
paquet (`AsDurableActivity` du bundle vers le cœur, sous `AsActivityHandler`) :
son renommage a vocation à rejoindre le **même** set, pas un second.

Après : **tâche 4** — `communication.xml`, `queue_topology.xml`,
`queue_publisher.xml`, `queue_consumer.xml` pour les cinq rôles (4.1), les
gestionnaires (4.2), et le verrou par exécution avec son test (4.3). Les quatre
sondages de la tâche 1 l'ont dessinée : la file n'offre **aucune** exclusion
mutuelle (1.5), le verrou de la 1.4 est donc la seule chose entre deux
consommateurs et un journal bifurqué, et une reprise qui arrive avant la purge
du verrou est acquittée sans être distribuée (1.3).

⚠ Frictions du banc, à savoir avant d'y toucher :
- dépôts de chemin en `"symlink": false` : recopier dans
  `magento/vendor/gplanchat/durable-magento` après chaque édition ; un
  changement de **métadonnées** demande en plus
  `composer update gplanchat/durable-magento`, qui lit la **copie principale** ;
- un module d'`app/code` ne s'autocharge pas sur Mage-OS, son entrée `psr-4` est
  dans `magento/composer.json` ;
- **mesurer sur une file sale répond à côté** : `php probe-queue.php purge`
  avant toute campagne ;
- le worktree n'a pas de `vendor`. Pour PHPUnit sur son code, un `--bootstrap`
  qui reprend l'autoloader de la copie principale et rebranche
  `Gplanchat\\Durable\\`, `…\\Durable\\Bundle\\` et `…\\Durable\\Magento\\`
  vers le worktree. Rebrancher le seul espace du module ne suffit pas dès que la
  tranche touche le cœur — vécu sur celle-ci.

Banc laissé propre : sonde purgée, `queue_lock` vide, `retry_inprogress_after`
à 1440, `env.php` sans clef `durable`, `di.xml` restauré.
