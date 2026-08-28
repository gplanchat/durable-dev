# Tasks

## 0. Sonder avant de construire

- [x] 0.1 **`ext-grpc` et la joignabilité du cluster depuis la maquette Sylius.** La question qui
      décide la forme de la démonstration : la maquette tourne sur `ghcr.io/sylius/sylius-php:8.3-alpine`,
      où il n'y a pas de grpc, mais son README documente `symfony serve` et sa CI l'exécute **sur
      l'hôte** avec `setup-php`. C'est ce second chemin qui compte.
      **Mesuré, et le résultat est un chemin étroit :**

      | PHP | grpc | curl | intl | pdo_mysql |
      |---|---|---|---|---|
      | 8.2 | ✅ | ✅ | ✅ | ✅ |
      | **8.3** | ✅ | ❌ | ✅ | ❌ |
      | 8.4 | ❌ | ✅ | ❌ | ❌ |

      La maquette **exige PHP ≥ 8.3** dans son propre `composer.json` — pas seulement par une
      dépendance de test —, donc 8.2 est exclu malgré son équipement complet. En 8.3 il manque
      `curl`, réclamé par `stripe/stripe-php` et le pilote Chrome : deux paquets que la
      démonstration n'exécute pas. L'installation passe avec `--ignore-platform-req=ext-curl`.
      **Et la sonde aboutit** : depuis l'autoload de la maquette, `DescribeNamespace` répond
      `default`, `ext-grpc` est chargée, PHP 8.3.33. Le banc peut parler au cluster.
- [x] 0.2 **Le pont Temporal était déjà déclaré en dépôt path** par `sylius/composer.json` ; seul
      son `require` ne le listait pas. L'ajouter est une ligne, pas un montage.

## 1. Le contrat partagé

- [x] 1.1 Un paquet `src/DurableDemoContracts/`, déclaré en dépôt path par les deux maquettes et
      **délibérément non publié** : deux applications de démonstration le consomment, en faire un
      paquet publiable traînerait la liste de contrôle SPLITS et le PAT pour rien. Son README le dit.
      Namespace `Gplanchat\Durable\Demo\Contracts\`, mappé par l'`autoload` de la racine — pas par
      son `require`, ni par `bin/splitsh-publish.sh`. Les deux `composer.lock` des maquettes sont
      remis à jour dans le même commit : la CI les installe avec `composer install`, qui refuse un
      lock en retard sur son `composer.json`.
- [x] 1.2 Le contrat `stock`, en deux interfaces : `StockServed` (`reserver`, immédiate) et
      `StockContract`, qui l'étend. Pour l'instant elles portent la même opération — la séparation
      existe pour ce que 2.x y ajoutera.
- [x] 1.3 Le contrat `facturation` : `FacturationServed` (`verifier`, immédiate) et
      `FacturationContract extends FacturationServed`, qui ajoute `encaisser`, remplie par un
      workflow.
      ⚠ **Les noms de paramètres du contrat et ceux du workflow qui remplit l'opération doivent
      coïncider** — `mapInputToArguments` associe par nom. Renommer d'un seul côté donne `null`, en
      silence. Le contrat le dit maintenant à l'endroit où on le lirait ; le garde exécutable
      appartient à §3.1, où le workflow existe.

      Les quatre interfaces ne portent que des scalaires et des tableaux : la charge est du JSON
      simple, décodée en tableau associatif de l'autre côté, et un paramètre typé objet y
      recevrait un tableau. `tests/unit/DurableDemoContracts/DemoNexusContractsTest.php` tient les
      trois manières de les écrire faux — un nom de service qui diverge entre les deux moitiés,
      une opération héritée invisible depuis le contrat de l'appelant, un type qui ne survit pas à
      l'aller-retour JSON — et les trois échouent en silence sans lui.

## 2. Sens 1 — Symfony appelle, Sylius sert (la forme immédiate)

Le sens le plus simple d'abord : il isole le risque d'infrastructure de celui des workers, la forme
immédiate n'exigeant aucun workflow qui remplit l'opération.

- [x] 2.1 La maquette Sylius gagne son profil Temporal — namespace `demo-boutique` — sans que le
      backend DBAL de son tableau de bord ne change de rôle.
      Les deux étaient déclarés **exclusifs** : `event_store.type: dbal` et `temporal.dsn` levaient
      une `LogicException`, « le journal ne peut pas avoir deux sources de vérité ». L'exclusion
      était juste tant que « DSN » voulait dire « le cluster est le journal ». `temporal.journal:
      false` sépare les deux phrases — le cluster est joignable pour ce qui en a besoin, et servir
      une opération Nexus en a besoin, mais c'est `event_store` qui nomme la source de vérité.
      Le refus reste, et nomme désormais la sortie.

      **La CI a montré ce qu'aucun essai local n'aurait montré :** un transport Messenger
      `temporal://` déclaré sans DSN fait échouer `doctrine:schema:create`. L'écouteur de schéma de
      Doctrine parcourt **tous** les transports, et le message qui sort est « Invalid temporal://
      DSN » — loin de Nexus, loin de Messenger, dans une commande qui crée une base. La boutique a
      donc deux profils de démonstration, `demo` et `demo_appelant`, et `dev`, `prod` et `test`
      n'ont ni DSN, ni transport, ni gestionnaire Nexus. La balise du gestionnaire est posée en
      YAML sous `when@demo` plutôt que par `#[AsNexusServiceHandler]`, l'attribut valant dans tous
      les environnements ; `symfony/` garde l'attribut, son banc ayant un DSN partout.
- [x] 2.2 `StockHandler` dans `sylius/`, `#[AsNexusServiceHandler]`, qui répond depuis le modèle de
      la boutique — les `ProductVariant` de Sylius, `onHand` moins `onHold`. Une tâche Nexus étant
      redélivrée, la réponse est écrite dans `app_durable_stock_reservation`, clée par
      l'identifiant de commande : la seconde livraison relit ce que la première a décidé au lieu de
      retenir du stock deux fois.

      **Trois pannes trouvées en chemin, toutes au-delà du prérequis annoncé, toutes gardées par un
      test :**

      1. L'invocateur manquant, décrit ci-dessous. `NexusHandlerInvoker` le comble dans le cœur, en
         réutilisant `PayloadToContractMethodInvoker` — une activité et une opération servie posent
         le même problème, une charge clée par nom et une méthode de contrat à appeler.
      2. `operationsClaimedByWorkflows()` balayait **toutes** les définitions du conteneur et
         appelait `class_exists()` sur chacune, donc chargeait chaque classe pour lire ses
         attributs. Il suffit qu'une seule étende un parent absent pour que la passe fasse une
         erreur fatale : `Symfony\Bundle\MakerBundle\Maker\AbstractMaker` est le cas réel qui l'a
         montré. La balise `durable.nexus_fulfilment` existait déjà pour ça — la passe la lit.
      3. La passe passait `NexusService` et `NexusOperationName` en **instances** comme arguments
         d'appel de méthode. Le conteneur compilait, et l'application ne démarrait pas : Symfony
         réécrit le conteneur en XML à chaque réchauffage en mode dev, et « Unable to dump a service
         container if a parameter is an object or a resource » ne parle ni de Nexus ni de la passe.
         Les objets-valeurs voyagent maintenant en `Definition`.

      ⚠ **Prérequis mesuré en §1 : la plomberie ne sait pas encore appeler un gestionnaire.**
      `NexusHandlerPass` enregistre la méthode typée du contrat, `NexusOperationRegistry::dispatch()`
      l'appelle en `$handler($payload)` — la charge entière en argument #1 — et exige un
      `NexusOperationResponse` en retour. Les deux moitiés sont cassées, et la sonde le montre :

      ```
      TypeError: SondeHandler::verify(): Argument #1 ($order) must be of type string, array given
      TypeError: NexusOperationRegistry::dispatch(): Return value must be of type
                 NexusOperationResponse, string returned
      ```

      Rien ne l'avait attrapé parce que les tests d'intégration enregistrent des fermetures
      `fn(mixed $payload): NexusOperationResponse` et que le test de la passe vérifie l'ajout de
      l'appel, jamais son exécution. Il manque donc deux choses, dans le cœur : l'association de la
      charge aux paramètres **par nom** — celle que `WorkflowDefinitionLoader::mapInputToArguments()`
      fait déjà pour les workflows — et l'emballage du retour ordinaire dans
      `NexusOperationResponse::completed()`. `documentation/user/nexus/_index.{md,fr.md}` montre un
      gestionnaire qui rend un `Verdict` : la doc décrit la forme voulue, pas celle qui marche.
- [x] 2.3 Le DSN Temporal de `symfony/` est activé — namespace `demo-metier`. Le bloc par défaut
      garde `dsn: null` : ce sont `when@dev` et `when@prod` qui posent `%env(DURABLE_DSN)%`, et la
      démonstration passe son namespace par la variable, sans toucher aux fichiers `.env` du banc.
      `bin/demo-nexus` imprime les deux commandes avec les bonnes valeurs.
      Côté boutique, il a fallu une ligne de plus que prévu : `TemporalBridgeBundle` dans
      `config/bundles.php`. C'est lui qui enregistre la fabrique Messenger `temporal://` ; sans lui
      Messenger répond « No transport supports Messenger DSN "temporal://…" », un message qui ne
      nomme ni Nexus ni le bundle manquant.
- [x] 2.4 `ReserverStockWorkflow` dans `symfony/`, qui prend le stub typé et attend le verdict. Il
      lit `StockContract` — le contrat de l'appelant — et non `StockServed`, et rien en lui ne sait
      si l'opération est servie tout de suite ou par un workflow. `durable:demo:nexus` le démarre.
- [x] 2.5 `bin/demo-nexus` : les deux namespaces et l'endpoint `demo-boutique-stock`, idempotent,
      qui imprime ensuite les deux commandes à lancer. L'endpoint désigne le namespace **et** la
      file que le worker de la boutique poll — c'est le seul endroit où les deux moitiés se
      rencontrent, et une faute de frappe y donne un appelant qui attend pour rien.
- [x] 2.6 Éprouvé pour de vrai : deux processus, deux namespaces, le verdict revient.

      | appel | réponse | effet dans la boutique |
      |---|---|---|
      | `CMD-1 MUG_BLUE=2` | `{reserve: true, manquants: []}` | `on_hold` 0 → 2 |
      | `CMD-2 MUG_RED=3` | `{reserve: false, manquants: {MUG_RED: 2}}` | rien |
      | `CMD-3 CAFETIERE=1 MUG_BLUE=1` | `{reserve: false, manquants: {CAFETIERE: 1}}` | rien — tout ou rien |
      | `CMD-1` rejoué | verdict identique | `on_hold` **reste** à 2 |

      Le banc : un `temporal server start-dev` (Nexus activé, contrairement à
      `temporalio/auto-setup:1.25.2` qui répond « Nexus APIs are disabled »), PostgreSQL pour la
      boutique — PHP 8.3 est la seule version de l'hôte qui ait `grpc`, et elle n'a pas
      `pdo_mysql` —, deux `messenger:consume`, une commande.

      Une note pour §5 : `manquants` est un tableau associatif, et PHP encode un tableau associatif
      **vide** en `[]` et non en `{}`. Un gestionnaire écrit en Go y lirait une liste là où il
      attend un objet. Le contrat s'en sort parce qu'il dit déjà que `manquants` n'a de sens que si
      `reserve` vaut `false` — mais la documentation doit le dire, puisqu'elle promet l'interop.

## 3. Sens 2 — Sylius appelle, Symfony sert (la forme différée)

- [x] 3.1 `EncaissementWorkflow` dans `symfony/`, `#[FulfilsNexusOperation]`, avec une activité de
      paiement et un délai de 12 s — au-delà des ~9 s d'une tâche Nexus, pour qu'une opération qui
      tiendrait dans ce budget n'ait visiblement pas besoin d'un workflow. Mesuré :
      `TimerStarted` 21:14:32 → `TimerFired` 21:14:44 → activité → terminé 21:14:46.

      Le garde du ⚠ de §1.3 est dans **la passe de compilation** et non dans une règle PHPStan :
      elle a déjà les deux côtés sous la main, et le refus au démarrage vaut pour tous les hôtes,
      y compris ceux qui ne lancent pas d'analyse statique. La règle est celle-ci — tout paramètre
      de workflow **sans valeur par défaut** doit être un paramètre du contrat ; un paramètre
      facultatif passe, l'absence étant alors une décision. Éprouvé par mutation sur
      l'application réelle : `$montan` au lieu de `$montant` refuse le conteneur en nommant les
      deux signatures.

      Une panne d'à côté, trouvée en lisant l'historique : `EncaissementWorkflow` appelait
      `timer()` sans l'attendre. `timer()` **rend** un awaitable ; `sleep()` est celui qui attend.
      L'historique portait un `TimerStarted` sans `TimerFired` et le workflow enchaînait.
      `TimerThenTickWorkflow`, l'exemple du banc, avait le même défaut depuis toujours — son nom
      promet l'inverse de ce qu'il faisait. Corrigé aussi.
- [x] 3.2 `CommandeWorkflow` dans `sylius/` appelle `verifier` **puis** `encaisser`, sur le même
      stub. C'est ce voisinage qui montre le point : l'immédiate et la différée s'écrivent pareil,
      et l'appelant ignore laquelle a coûté douze secondes à quelqu'un d'autre.

      La boutique a donc besoin d'un second profil, `APP_ENV=demo`, dont le journal est le cluster.
      Un appel Nexus part d'un workflow, et `EventStoreCommandBuffer` refuse d'ordonnancer une
      opération depuis un journal SQL — il n'a pas de serveur à qui l'adresser. Servir et appeler
      ne tiennent pas dans la même configuration Durable, et c'est à cela que ressemble un vrai
      déploiement : le processus qui rend le tableau de bord et celui qui exécute les workflows
      sont deux déploiements du même code.
- [x] 3.3 L'endpoint `demo-metier-facturation`, créé par le même `bin/demo-nexus`.
- [x] 3.4 Éprouvé, et mieux que prévu : pendant une mise au point, le worker qui devait faire
      avancer l'encaissement est resté **éteint quatre minutes**. L'opération est restée en
      `NEXUS_OPERATION_STARTED`, l'appelant n'a rien consommé, et tout s'est terminé normalement au
      retour du worker. C'est la preuve que l'attente ne tient rien d'ouvert — une preuve qu'un
      passage nominal n'aurait pas donnée.

      | appel | réponse | durée |
      |---|---|---|
      | `FACT-10 1200` | `verifiee.acceptee: true`, `encaissement.recu: RECU-EUR-FACT-10` | 14,0 s |
      | `FACT-11 4200 USD` | `verifiee.acceptee: false`, `motif: devise USD non prise en charge`, `encaissement: null` | 0,4 s |

      Deux pannes de configuration en chemin, toutes deux à la charge des maquettes : les
      gestionnaires Nexus étaient déclarés aussi en `APP_ENV=test`, où il n'y a pas de cluster — le
      garde de démarrage refusait alors toute la suite. `autoconfigure: false` sous `when@test`
      retire la balise sans retirer la classe. Et les deux commandes autowiraient
      `WorkflowClientInterface`, qui n'existe pas sans DSN : elles le prennent maintenant en
      optionnel et le disent.

## 4. Faire tourner la démonstration

- [x] 4.1 **Compter les processus avant de promettre.** Il y en a **cinq**, pas six : la boutique
      n'a pas de worker d'activité, parce que `CommandeWorkflow` n'a pas d'activité — il n'appelle
      que des opérations Nexus. Un sixième processus ne ferait que poller une file vide, et la
      démonstration mentirait sur ce qu'elle demande.

      L'énoncé initial, gardé parce que sa moitié qui compte tient toujours :
      **Compter les processus avant de promettre.** La forme différée n'avance que si un worker
      de tâches de workflow poll du côté servant — les tests d'intégration les pilotaient à la main.
      La démonstration en demande donc, par maquette : un worker Nexus, un worker de workflow, un
      worker d'activité. **Six processus.** C'est la différence entre une démonstration qui tourne
      et une qui reste suspendue sans rien dire.
- [x] 4.2 `demo/lancer.sh` : les cinq workers, `--etat` pour savoir qui tourne, `--arreter` pour
      tout éteindre, et les deux commandes d'appel imprimées avec les bonnes valeurs. Il refuse de
      démarrer si l'endpoint n'existe pas, en nommant `bin/demo-nexus`.
- [x] 4.3 `demo/README.md` : les prérequis mesurés en 0.1 — PHP 8.3,
      `--ignore-platform-req=ext-curl` — et **le fait que les deux endpoints ne sont pas des
      résidus de test**. La suite d'intégration en crée d'éphémères sur le même cluster et les
      supprime ; ceux-ci sont stables, nommés `demo-*`, et personne ne doit les « nettoyer ».

## 5. Le dire

- [x] 5.1 Une section « Deux applications, en vrai » à la fin de la page Nexus, dans les deux
      langues : le tableau des deux maquettes, les deux appels côte à côte sur le même stub, et
      l'historique de l'appelant qui, lui, les distingue. Vérifié sur un build `--minify` servi en
      HTTP — 8 `h2`, 2 tableaux, 12 blocs de code de chaque côté.

      Deux corrections que la démonstration a rendues nécessaires :

      1. **Les exemples de la page étaient faux.** `verify(Order $order): Verdict` — un paramètre
         typé objet arrive en tableau associatif et lève un `TypeError` à l'appel, pas à l'écriture
         du contrat. Les signatures portent maintenant des scalaires et des tableaux, et un
         paragraphe dit pourquoi, avec le détail PHP du tableau associatif vide encodé `[]`.
      2. **`temporal.journal: false` n'était nulle part.** La clé est née en §2.1 ; sans elle, un
         lecteur dont le tableau de bord lit DBAL conclut de la page qu'il ne peut pas servir Nexus.
- [x] 5.2 La citation de `sylius/config/packages/durable.yaml` nomme DUR037, et dit en passant ce
      qu'est DUR035 — pour que la prochaine lecture n'ait pas à aller vérifier.
