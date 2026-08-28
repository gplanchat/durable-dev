# change/magento-module

Worktree : `.claude/worktrees/magento`. PR **#168** — tranche **1.3**, verte
(22/22), `CLEAN / MERGEABLE`, en attente de fusion par l'auteur.

Ce qu'elle établit : un consommateur tué au milieu d'un message laisse la ligne
`IN_PROGRESS` avec zéro essai et un verrou dans `queue_lock`, sans lettre morte
ni trace. Le rattrapage demande deux tâches cron, et **leur ordre décide** : une
reprise qui arrive avant la purge du verrou fait acquitter le message *sans le
distribuer*. Les défauts livrés (un jour de reprise, purge horaire) sauvent la
mise ; les raccourcir ne le fait plus.

L'instrument vit au **banc** (`magento/app/code/Gplanchat/DurableProbe`), pas
dans le paquet publié : un sujet dont le gestionnaire ne fait que dormir n'est
aucun des cinq rôles de la 4.1.

Prochaine tranche : **1.5** — un consommateur face à un transport en longue
interrogation. Le sujet de sonde de la #168 est déjà l'instrument.

⚠ Trois frictions du banc, à savoir avant d'y toucher :
- les dépôts de chemin sont en `"symlink": false`, donc éditer `src/DurableModule`
  ne change rien à `magento/vendor/gplanchat/durable-magento` tant qu'on n'y a
  pas recopié les fichiers ;
- un module d'`app/code` ne s'autocharge pas sur Mage-OS : son entrée `psr-4`
  est dans `magento/composer.json`, et le message d'erreur ne désigne pas sa
  cause ;
- la copie principale portait des copies **non suivies** de l'overlay `magento/`
  et de `src/DurableModule` qui bloquaient `git merge` — écartées vers un
  scratchpad, le merge les a réécrites à l'identique.

Débris de banc laissés par la campagne, sans conséquence : des messages de sonde
dans `queue_message`, deux lignes dans `queue_lock`, et une ligne
`core_config_data` pour `retry_inprogress_after` (remise à `1440`, sa valeur
livrée, mais la ligne n'existait pas avant).
