# change/magento-module

Worktree : `.claude/worktrees/magento`. PR **#172** — tranche **2.3**, verte, en
attente de fusion. Tâche 1 finie (#168, #170 fusionnées) ; 2.1, 2.2, 2.3, 3.2.

**2.3 a changé de mécanisme sur décision de l'auteur** : le refus des ponts SQL
n'est pas du code, c'est un `conflict` dans le `composer.json` du module.
Mesuré au banc — `composer require gplanchat/durable-bridge-dbal` finit en
« Conclusion: remove gplanchat/durable-magento » et n'écrit rien. Partent avec
le code : `Config\Backend`, son exception, la garde dans la fabrique, son test
unitaire — **et la surface de configuration elle-même**. Sans clef
`durable/backend`, il n'y a plus rien à mal orthographier. La tâche 5 est
l'endroit où un second backend, donc un choix, commence à exister.

Ce que `conflict` ne porte pas : la raison. Elle reste dans `ALLOWED.magento`,
le sélecteur et `design.md`. Le delta de spec a suivi — le `Scenario:` dit
« the installation is refused », plus « startup fails ».

Prochaine tranche : **3.1** — la déclaration des `#[Workflow]` et `#[Activity]`,
le conteneur de Magento n'ayant pas les tags de Symfony. La 3.2 tourne
aujourd'hui sur un enregistrement à la main dans la commande de démo.

⚠ Frictions du banc, à savoir avant d'y toucher :
- dépôts de chemin en `"symlink": false` : éditer `src/DurableModule` ne change
  rien à `magento/vendor/gplanchat/durable-magento` sans recopie — et pour
  qu'un changement de **métadonnées** prenne, il faut passer par
  `composer update gplanchat/durable-magento`, qui lit le paquet de chemin dans
  la **copie principale**, pas dans le worktree ;
- un module d'`app/code` ne s'autocharge pas sur Mage-OS, son entrée `psr-4` est
  dans `magento/composer.json` ;
- **mesurer sur une file sale répond à côté** : `php probe-queue.php purge`
  avant toute campagne ;
- le worktree n'a pas de `vendor` (428 Mo dans la copie principale). Pour
  PHPUnit sur son code : l'autoloader de la copie principale + un
  `setPsr4('Gplanchat\\Durable\\Magento\\', [<worktree>/src/DurableModule/])`
  passé en `--bootstrap`, sinon on teste le code de `main`.

Banc laissé propre : sonde purgée, `queue_lock` vide, `retry_inprogress_after`
à 1440, `env.php` sans clef `durable`. La copie principale est rendue propre
elle aussi ; son `magento/vendor` porte le `conflict` en avance sur `main`, ce
qu'un `composer update gplanchat/durable-magento` après fusion remettra d'aplomb.
