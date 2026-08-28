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

⚠ **Ce qui manque pour que la grille montre quelque chose** : aucun worker ne
draine la file de tâches du journal. Une exécution lancée contre le cluster n'a
personne pour l'avancer. C'est le reste de la 5.1, et la 5.2 en dépend.

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

Reste : les workers de la 5.1, puis 5.2 et 5.3 ; la tâche 4 (la file de Magento)
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
