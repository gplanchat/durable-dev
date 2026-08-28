# change/magento-module

Worktree : `.claude/worktrees/magento`. Branche recréée après la fusion de la
#193 (le distant l'avait supprimée, ce qui rendait cette prise périmée aux yeux
de `bin/prises-check.sh` et faisait rougir toutes les PR ouvertes). **PR #194**
en cours, minuscule.

**Tout ce qui précède est fusionné** : tâche 1 entière, 2.1–2.3, 3.1–3.2, 5.1
(backend Temporal + worker + écran d'administration), le codec de file, et
l'orchestration de reprise descendue au cœur derrière `WorkflowTimerDispatcher`.

✅ **Tâche 4 abandonnée — décision de l'auteur, PR #197.** Rien de Durable ne
circule sur le `MessageQueue` de Magento : `TemporalWorkflowCommandBuffer`
ordonnance les activités en commandes Temporal sur une file de tâches Temporal,
et `EventStoreCommandBuffer` — celui qui met un `ActivityMessage` sur la file de
l'hôte — est le chemin non-Temporal. Magento n'a pas de journal natif et n'en
aura pas. Les tâches 4 et 5 étaient des alternatives, pas une séquence.

Parti avec : le codec d'`ActivityMessage` et ses tests, qui n'avaient plus
d'appelant. Resté : les deux mesures sur lesquelles il était bâti (l'encodeur
vide un objet sans lever, `string[]` perd les clés) et la §1.4 (le verrou est
partagé entre processus). Le delta de spec a suivi — l'exigence parle désormais
de processus qu'un exploitant supervise déjà, et interdit explicitement une
seconde file.

Prochaine tranche : **le worker d'activités Temporal**, qui ferme la 5.3 — c'est
lui qui manquait quand l'exécution restait coincée sur une activité distribuée
en mémoire.

**Tâche 4, PR #190 (elle porte aussi la 5.3).** Le codec de file est écrit et
gardé : `ActivityMessage` ⇄ JSON, refusant `options` et `retryDelay` **en les
nommant** plutôt qu'en les perdant. Mesuré d'abord — une activité ordinaire n'en
porte aucun des deux.

✅ **Fait — PR #193.** Les 279 lignes sont au cœur, derrière un port. Les cinq rôles ne
sont pas cinq gestionnaires minces : `ResumeWorkflowHandler` fait **138 lignes
avec 15 imports du cœur**, `FireWorkflowTimersHandler` 86 de plus. C'est
l'orchestration de reprise du moteur en veste Symfony, pas un adaptateur d'hôte.
Chiffré avant d'arbitrer : **279 lignes, dont 21 touchent Symfony** (imports
compris), et les 21 se réduisent à deux choses — `Uuid::v7()`, que
`ExecutionId::generate()` remplace déjà dans le cœur, et « publier
`FireWorkflowTimersMessage`, éventuellement différé, après l'unité de travail
courante », qui demande **un port**, de la forme de `WorkflowResumeDispatcher`.
`AsyncChildWorkflowFailureProjector` (55 lignes) n'a aucun import Symfony.
Six hôtes du sélecteur ne passent pas par le bundle : le coût de l'alternative
n'est pas 279 lignes, c'est 279 × 6 plus la divergence.

Le seul endroit qui risquait de n'être pas mécanique, `DispatchAfterCurrentBusStamp`,
s'est réglé en le mettant **dans le contrat du port** : « après l'unité de travail
courante » y est écrit, parce que sans lui le réveil se délivre au milieu de la
passe en cours, qui relit un journal à moitié écrit. Un hôte qui publie dans une
file l'obtient gratuitement, mais il doit le savoir.

Ce qui a bougé : `ResumeWorkflowHandler` et `FireWorkflowTimersHandler` vers
`Gplanchat\Durable\Handler\`, `AsyncChildWorkflowFailureProjector` vers
`Gplanchat\Durable\Workflow\`. Nouveau port `WorkflowTimerDispatcher` + son
implémentation Messenger dans le bundle (quinze lignes). `Uuid::v7()` remplacé
par `ExecutionId::generate()`. Trois renommages dans `durable-upgrade.php`, une
section d'`UPGRADE.md`, et un avertissement pour qui décorait ces gestionnaires :
leur argument de bus est devenu un `WorkflowTimerDispatcher`.

Preuve : 654 tests verts en local, 22/22 en CI — les tests d'intégration du
bundle compris, qui éprouvent le câblage DI.

Note : `main` a apporté les sept renommages d'attributs de la session Nexus
(`Workflow` → `AsWorkflow`, etc.). Le module a été migré **par le set
`durable-upgrade.php` lui-même**, ce qui l'éprouve pour de vrai : six fichiers
réécrits, plus un seul ancien nom.

Reste ensuite : la 5.2 (une commande passée depuis la boutique), puis la
tâche 6. ; la tâche 4 (la file de Magento)
et la 4.3 ; puis la tâche 6.

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
