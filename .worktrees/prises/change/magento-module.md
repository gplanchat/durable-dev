# change/magento-module

Worktree : `.claude/worktrees/magento`. PR #168 fusionnée — 1.1 à 1.4, 2.1, 2.2,
3.2 sont livrées.

Tranche en cours : **1.5** — un consommateur Magento face à un transport en
longue interrogation. L'instrument existe déjà (le module de sonde du banc, dont
le gestionnaire tient un message aussi longtemps qu'on lui dit).

Trois questions, dans l'ordre où elles mordent :
1. `queue:consumers:start` supporte-t-il un gestionnaire qui tient des minutes,
   ou impose-t-il sa propre limite ?
2. la connexion MySQL survit-elle ? (`wait_timeout` du banc : 28800 s)
3. **la minuterie de reprise redistribue-t-elle le message pendant qu'il est
   encore traité ?** C'est la question qui compte : un worker en longue
   interrogation tient un message par construction, et la 1.3 a montré que la
   reprise ne demande à personne si le premier a fini.
