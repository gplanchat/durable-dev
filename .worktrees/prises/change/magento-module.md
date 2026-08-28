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

Tranche **en cours** : **l'écran d'administration repris sur les grilles
standard de Magento**, et un **écran de détail** d'exécution.

Pourquoi ce n'était pas déjà le cas : je ne l'ai pas décidé, j'ai écrit le plus
court qui s'affichait — un `<table class="admin__table-primary">` dans un phtml.
La grille standard est faisable : `Magento\Ui\DataProvider\AbstractDataProvider`
est l'échappatoire documentée pour une source qui n'est pas une collection SQL,
et `getData()`, `addFilter()`, `setLimit()` s'y redéfinissent.

⚠ Le point de friction à mesurer : la grille pagine par **offset**
(`setLimit($offset, $size)`) tandis que `listRuns()` pagine par **curseur** de
continuation. Il faudra soit marcher les curseurs, soit borner une fenêtre et
paginer dedans — et dire lequel, avec son plafond.

Le détail a déjà tout ce qu'il faut côté port : `readHistory(WorkflowRunDescription)`
rend la liste des événements, et `checkHealth()` existe aussi, que l'écran actuel
n'utilise pas non plus.

✅ **Tâche 6 aux trois quarts — PR #205 fusionnée.** DUR046 (6.1), les pages
paquets et la page Backends dans les deux langues (6.3), et les deux OST (6.4).
La ligne Magento d'OST004 a quitté le tableau de ce qui n'est pas construit.

⚠ Les sections de documentation s'ouvrent sur un **avertissement : le paquet
n'est pas sur Packagist**. Il partira avec la publication, pas avant — documenter
un `composer require` qui ne résout pas serait la documentation qui ment.

Reste : **6.2**, le sélecteur qui sort de `planned` — *par le canevas*, pas par
le fichier généré, donc ça demande la main de l'auteur ou la boucle designer.
Puis, hors cases : la **publication** (dépôt satellite + portée du jeton avant la
ligne dans SPLITS), le **job de CI qui démarre** Magento (4bis.2), et le
**manuel de l'exploitant** (les défauts de Magento sont porteurs : les deux
tâches cron et le délai de reprise sont des pièces de la garantie).

✅ **5.3 VERTE — PR #203.** Le test d'acceptation du change entier passe :
commande partie sur la grappe, les **deux** workers tués en pleine réservation,
relancés, et l'ordre se termine — `'notify:charge:ORD-acceptation-…'` — avec
**un seul débit**.

Il manquait deux choses et non une. Le worker d'activités, oui. Mais aussi de
quoi **démarrer sur la grappe** : `MagentoRuntime::run()` exécute ici et
maintenant, donc ses activités partaient dans le transport en mémoire quel que
soit le journal dessous. Le worker seul n'aurait rien eu à dépiler.
`WorkflowClient::startAsync()` est la porte.

`durable:worker --role=journal|activity` — deux files distinctes côté Temporal,
dont un exploitant règle le parallélisme séparément.

⚠ Méthode payée une deuxième fois : la première campagne a compté **trois**
débits, ce qui ressemblait à un doublon jusqu'à ce que je lise le journal au lieu
de le compter — trois commandes **différentes**, restes d'essais précédents en
file. Le test d'acceptation draine les files avant de mesurer.

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


## Publication du paquet — 2026-08-28

Fait dans le dépôt : `src/DurableModule/README.md` et `LICENSE` (les six paquets
publiés en ont, celui-ci était le seul sans), et la ligne
`"src/DurableModule/|durable-magento"` dans `bin/splitsh-publish.sh`.
`composer validate --strict` passe.

⚠ **Le satellite `gplanchat/durable-magento` existe déjà**, créé le 2026-03-29,
et il **n'est pas vide** : son `main` est le split de `af3e51be`, quand ce
préfixe tenait un tout autre module (`Api`, `Model`, une commande de
consommation), retiré depuis par `e9b24e9c`. Son arbre correspond exactement à
`src/DurableModule/` à ce commit-là — c'est donc un vrai split du même préfixe,
et le split d'aujourd'hui devrait l'avoir pour ancêtre : la première poussée
avance **sans forcer**. Si elle est refusée, le `workflow_dispatch` avec `force`
archive la tête sous `refs/heads/archive/` avant de la remplacer. Ne pas
supprimer le dépôt : la portée du PAT est par dépôt, et un dépôt recréé n'y est
plus.

⚠ **Le satellite est PRIVÉ**, seul des dix. Packagist ne lira rien tant qu'il ne
sera pas public.

Gestes hors dépôt restants, **dans cet ordre** ([[splitsh-nouveau-satellite]]) :
1. rendre `gplanchat/durable-magento` public ;
2. l'ajouter à la portée du PAT `SPLITSH_PUSH_TOKEN` (fine-grained, *Only select
   repositories*, Contents: Read and write) ;
3. **puis seulement** fusionner la PR #209 — c'est elle qui porte à la fois le
   préfixe et sa ligne dans `SPLITS`. L'inverse (la ligne sans le préfixe) ne
   rougirait pas un satellite mais **tuerait le job entier** : `split_sha` fait
   `exit 1` quand splitsh-lite ne rend rien pour un préfixe absent ;
4. soumettre à Packagist.

Le paquet arrivera en `dev-main` avec **zéro version** — un préfixe ajouté après
coup ne rattrape pas les tags passés, exactement comme
`durable-bridge-illuminate`. Première version au prochain tag, pas en rejouant
`v0.1.0-alpha7`. La section *Release state* du README le dit.

L'avertissement « pas sur Packagist » des pages de documentation reste jusqu'à la
soumission.
