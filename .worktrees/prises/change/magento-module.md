# change/magento-module

Worktree : `.claude/worktrees/magento`. PR **#168** ouverte — tranche **1.3**.

Ce qu'elle établit : un consommateur tué au milieu d'un message laisse la ligne
`IN_PROGRESS` avec zéro essai et un verrou dans `queue_lock`, sans lettre morte
ni trace. Le rattrapage demande deux tâches cron, et **leur ordre décide** : une
reprise qui arrive avant la purge du verrou fait acquitter le message *sans le
distribuer*. Les défauts livrés (un jour de reprise, purge horaire) sauvent la
mise ; les raccourcir ne le fait plus.

Prochaine tranche : **1.5** — un consommateur face à un transport en longue
interrogation. Le sujet de sonde de la #168 est déjà l'instrument.

⚠ Deux frictions du banc, à savoir avant d'y toucher :
- les dépôts de chemin du banc sont en `"symlink": false`, donc éditer
  `src/DurableModule` ne change rien à `magento/vendor/gplanchat/durable-magento`
  tant qu'on n'y a pas recopié les fichiers ;
- la copie principale portait des copies **non suivies** de l'overlay `magento/`
  et de `src/DurableModule`, qui bloquaient `git merge` — écartées vers un
  scratchpad, le merge les a réécrites à l'identique depuis `main`.
