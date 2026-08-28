# change/magento-module

Worktree : `.claude/worktrees/magento`. PR **#172** — tranche **2.3**, en
attente de fusion. Tâche 1 finie (PR #168, #170 fusionnées) ; 2.1, 2.2, 2.3 et
3.2 aussi.

2.3 : le choix de backend vit dans `env.php` sous `durable/backend`.
`Backend::fromConfiguredName()` accepte `memory` et `temporal` — le vocabulaire
du sélecteur, `ALLOWED.magento`, pas le `in_memory` du bundle — refuse `dbal` et
`illuminate` avec la raison d'hôte, refuse un nom inconnu avec la liste, et
refuse `temporal` tant que le module ne le câble pas. **Première tranche que la
CI sait garder** : la décision est du PHP sans une ligne de Magento.

⚠ « Au démarrage » est plus faible ici que sous un bundle : pas d'extension de
bundle, `setup:di:compile` n'instancie rien. Le refus tombe à l'amorçage d'un
processus, pas à la compilation. Écrit tel quel dans le design.

Prochaine tranche : **3.1** — un mécanisme de déclaration pour les `#[Workflow]`
et `#[Activity]`, le conteneur de Magento n'ayant pas les tags de Symfony. La
3.2 tourne déjà avec un enregistrement à la main dans la commande de démo : 3.1
est ce qui la remplace.

⚠ Frictions du banc, à savoir avant d'y toucher :
- dépôts de chemin en `"symlink": false` : éditer `src/DurableModule` ne change
  rien à `magento/vendor/gplanchat/durable-magento` sans recopie ;
- un module d'`app/code` ne s'autocharge pas sur Mage-OS, son entrée `psr-4` est
  dans `magento/composer.json` ;
- **mesurer sur une file sale répond à côté** : `php probe-queue.php purge`
  avant toute campagne ;
- le worktree n'a pas de `vendor` (428 Mo dans la copie principale). Pour lancer
  PHPUnit sur son code : l'autoloader de la copie principale + un
  `setPsr4('Gplanchat\\Durable\\Magento\\', [<worktree>/src/DurableModule/])`
  passé en `--bootstrap`. Sans ça on teste le code de `main` en croyant tester
  le sien.

Banc laissé propre : sonde purgée, `queue_lock` vide, `retry_inprogress_after`
à 1440, `env.php` sans clef `durable`.
