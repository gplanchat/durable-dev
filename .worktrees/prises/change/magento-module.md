# change/magento-module

Worktree : `.claude/worktrees/magento`. PR **#170** — tranche **1.5**, en
attente de fusion. **La tâche 1 est finie** : 1.1 à 1.5.

1.5 : le consommateur ne meurt ni de faim ni d'un délai — un gestionnaire de
200 s va au bout, `wait_timeout` du banc à 28800 s. Mais la minuterie de reprise
ne regarde que `updated_at` : deux processus vivants ont traité **le même
message en même temps** (pids 442111 et 445235). Un worker tient son message par
construction, donc **le worker ne peut pas être un message de file** — la 5.1
cesse d'être une préférence. Et la file n'offre aucune exclusion mutuelle, ce
qui fait du verrou de la 1.4 la seule chose entre deux consommateurs et un
journal bifurqué.

Prochaine tranche : **2.3** (refus au démarrage de DBAL et Illuminate, par nom —
et la 1.4 a montré qu'il ne peut pas se fonder sur `get_class()` du gestionnaire
de verrous, qui rend une `Lock\Proxy`) ou **3.1** (découverte des `#[Workflow]`
et `#[Activity]`).

⚠ Frictions du banc, à savoir avant d'y toucher :
- dépôts de chemin en `"symlink": false` : éditer `src/DurableModule` ne change
  rien à `magento/vendor/gplanchat/durable-magento` sans recopie ;
- un module d'`app/code` ne s'autocharge pas sur Mage-OS, son entrée `psr-4` est
  dans `magento/composer.json`, et l'erreur ne désigne pas sa cause ;
- **mesurer sur une file sale répond à côté** : `php probe-queue.php purge`
  avant toute campagne. Payé une fois.
- la copie principale porte parfois des copies non suivies de l'overlay
  `magento/` qui bloquent `git merge` — les écarter, le merge les réécrit.

Banc laissé propre : sonde purgée, `queue_lock` vide, `retry_inprogress_after`
à 1440.
