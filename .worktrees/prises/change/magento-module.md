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

**PR #175 fusionnée** — elle a emporté la tranche 3.1 *et* la procédure de
migration de sa rupture : set Rector cumulatif `durable-upgrade.php`, `UPGRADE.md`
à la racine (le dépôt n'avait aucun endroit où documenter une montée de version),
et ce que Rector ne peut pas faire écrit en toutes lettres — un conteneur Symfony
compilé garde le nom pleinement qualifié et veut son `cache:clear`.
⚠ C'est **`chantier-nexus`**, pas `splitsh-integration-alpha8`, qui déplace
`AsDurableActivity` du bundle vers le cœur sous `AsActivityHandler` : son
renommage est **déjà** dans le même set : il a supprimé son
`durable-attributes-alpha8.php` en doublon et fusionné ses huit entrées dans
`durable-upgrade.php`, qui en porte neuf. Ses sept renommages d'attributs me
concerneront le jour où le module référencera les attributs — le set les couvre,
et Magento n'ayant pas de conteneur compilé, il n'y a pas de `cache:clear` à
faire de ce côté. (La session
`splitsh-integration-alpha8` est sur le chantier Laravel et ne touche à rien de
tout ça — je m'étais trompé de destinataire.)

**PR #182** — verte (22/22), `CLEAN`. Elle porte **l'écran d'administration**
demandé et la tranche **5.1** qui le rend capable de montrer autre chose que du
vide. #179 (conception de la 4.1) est fusionnée.

`System > Durable processes > Process history`. Vérifié en HTTP, connecté, dans
les deux états : sans DSN la page dit pourquoi une liste vide est la bonne
réponse ; avec `durable/temporal/dsn` dans `env.php`, l'avertissement disparaît
et la grille lit le cluster. Rien n'est réimplémenté —
`InMemoryWorkflowRunCatalog` lit n'importe quel magasin d'événements et rend les
mêmes `WorkflowRunDescription` que le tableau de bord Sylius.

Pour le voir : `cd magento && php -S 127.0.0.1:8080 -t pub/ phpserver/router.php`,
puis `http://127.0.0.1:8080/admin` — **durable / Durable123!**

**La grille rend des lignes.** Le banc a son DSN posé en permanence
(`durable/temporal/dsn` → `temporal://127.0.0.1:7234?namespace=default&tls=0`),
et chaque `durable:demo` y ajoute une exécution :

    d81bfb25-…  DurableJournal  running  2026-08-28 09:23:45

⚠ **Le défaut qui l'avait rendue vide, à ne pas rejouer** : un catalogue ne se
dérive **pas** d'un journal. `InMemoryWorkflowRunCatalog` tient sa propre carte,
alimentée par `recordStart()`/`recordOutcome()` dans le processus qui exécute —
une requête d'administration n'exécute rien. Lister les exécutions d'une grappe,
c'est demander à la grappe : `TemporalWorkflowRunCatalog`, que le pont livre déjà.

⚠ Une réserve, et **une correction** : le nom affiché est `DurableJournal`, le
type Temporal qui *porte* le journal, pas le type métier — remonter le second
appartient au change du tableau de bord.

Mais le statut `running` **n'était pas dû à l'absence de worker**, contrairement
à ce que j'avais écrit. Le worker existe maintenant (PR #187), la file est
drainée, et le cluster répond toujours `RUNNING` pour chaque `DurableJournal` :
le workflow du journal est **long par construction**, c'est le journal lui-même,
il ne se ferme pas parce qu'une exécution s'est terminée. La colonne « Status »
ne peut donc lire que `running` sur cet hôte tant qu'elle reflète le statut
Temporal. Ce qui distingue fini de en-cours vit dans les **événements** du
journal (`TemporalRunHistoryReader` + `TemporalHistoryCursor`, désormais passé au
catalogue). En faire une colonne honnête appartient au change du tableau de bord.

⚠ Le banc a deux copies de `vendor/` rafraîchies à la main (`durable-magento` et
`durable-bridge-temporal`) : `composer update` les réécrit depuis la **copie
principale**, qui est à l'état de `main`. Après fusion de la #182, un
`composer update gplanchat/durable-magento gplanchat/durable-bridge-temporal`
remet tout d'aplomb.

⚠ Trois contraintes d'hôte de plus, trouvées en posant l'écran (toutes dans
`design.md`) :
- Magento résout un **contrôleur par convention depuis le nom du module**, pas
  depuis l'autochargement — d'où une seconde entrée `psr-4` pour `Controller/`.
  Sans elle : menu affiché, route déclarée, et un 404 dans le châssis d'admin ;
- un **argument de constructeur optionnel n'est pas auto-câblé**, Magento prend
  son défaut — le DSN n'était jamais lu, sans une ligne d'erreur ;
- **renommer une classe que le conteneur instancie** laisse un intercepteur
  périmé dans `generated/code/`, que `--keep-generated` ne retire pas ; le
  symptôme est « There are no commands defined in the "durable" namespace ».

⚠ La copie principale porte une modification locale de
`src/DurableModule/composer.json` (la seconde entrée `psr-4`) : elle est
identique à ce que livre la #182, et le banc en a besoin pour tourner d'ici la
fusion.

**PR #185** — le README du banc décrit enfin le banc qui existe. Étaient partis :
`Gplanchat_DurableModule`, `gplanchat/durable-module`, un tableau de bord sous
*Stores > Configuration* avec frise et couloirs, un champ « Temporal DSN » dans
l'administration, une tranche « reasoning » de cinq activités, une commande
`durable:sample`, et le port 7233. Ajoutés : le tableau des ports, où vit le
journal, les deux réserves de l'écran, les six contraintes d'hôte, comment faire
suivre le banc quand le module change, et les sondes. Les commandes documentées
ont été relancées avant d'être écrites.

**PR #187** — `bin/magento durable:worker` draine la file du journal. Une
commande et pas un consommateur de file : la 1.5 a mesuré ce que Magento fait
d'un message tenu trop longtemps. ⚠ Ses bornes sont vérifiées **entre** deux
tâches, donc le processus peut dépasser sa limite d'une longue interrogation.

**PR #190** — le §5.3 lancé pour de vrai, et un défaut du **cœur** trouvé en le
lançant.

**5.3, moitié verte, et c'est la bonne moitié.** Commande débitée, `kill -9`
pendant la réservation, trois relances sous le même identifiant : **un seul
débit**. Le journal rejoue au lieu de refaire (mesuré aussi à part : une
exécution terminée relancée sous son identifiant grandit d'**un** événement, pas
de treize). Ce qui ne se produit pas encore : l'exécution ne repart pas jusqu'au
bout — `WorkflowStuckException`, parce que `reserve` avait été distribuée dans le
transport en mémoire du processus mort. **Reprendre demande une distribution
d'activité durable : c'est la tâche 4.** La case 5.3 reste ouverte et dit
maintenant ce qui la ferme.

⚠ **Le cœur importait le bundle Symfony.** `InMemoryWorkflowRunner` appelait
`Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator` alors que
`gplanchat/durable` ne requiert ni bundle ni pont : erreur fatale à la première
reprise sur tout hôte sans Symfony. Descendu dans `Gplanchat\Durable\Timer\`,
avec Rector et `UPGRADE.md`. Une **garde** parcourt désormais les 183 fichiers de
`src/Durable` et échoue sur tout `use` d'un hôte ou d'un pont — les sept `@see`
en commentaire sont tolérés.

**Tâche 4, PR #190 (elle porte aussi la 5.3).** Le codec de file est écrit et
gardé : `ActivityMessage` ⇄ JSON, refusant `options` et `retryDelay` **en les
nommant** plutôt qu'en les perdant. Mesuré d'abord — une activité ordinaire n'en
porte aucun des deux.

✅ **Arbitré le 28/08 : les 279 lignes descendent au cœur.** Les cinq rôles ne
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

⚠ Le seul endroit qui pourrait n'être pas mécanique : `DispatchAfterCurrentBusStamp`,
la seule ligne portant une garantie propre à Messenger — « ne redistribue pas
tant que le message courant n'est pas fini ». Le port doit décider ce que ça veut
dire sans bus.

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
