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
- [ ] 2.3 Le DSN Temporal de `symfony/` est activé — namespace `demo-metier`. Sa configuration a
      `temporal.dsn: null` aujourd'hui : « Temporal sur les maquettes » vaut pour les deux.
- [ ] 2.4 Un workflow appelant dans `symfony/`, qui prend le stub typé et attend le verdict.
- [ ] 2.5 L'endpoint `demo-boutique-stock`, créé par un script d'opérateur.
- [ ] 2.6 Éprouvé pour de vrai : deux processus, deux namespaces, le verdict revient.

## 3. Sens 2 — Sylius appelle, Symfony sert (la forme différée)

- [ ] 3.1 `EncaissementWorkflow` dans `symfony/`, `#[FulfilsNexusOperation]`, avec une activité et
      un délai — pour que l'attente soit réelle et non simulée.
      Avec lui vient le garde du ⚠ de §1.3, écrit une fois et générique : pour toute classe portant
      `#[FulfilsNexusOperation]`, les noms de paramètres doivent être ceux de la méthode du contrat
      qu'elle nomme. Une règle PHPStan dans `src/DurablePhpstan/` le dirait à tous les projets et
      pas seulement à la démonstration — c'est l'endroit à préférer si le coût y est le même.
- [ ] 3.2 Un workflow de commande dans `sylius/` qui appelle `facturation/encaisser`.
- [ ] 3.3 L'endpoint `demo-metier-facturation`.
- [ ] 3.4 Éprouvé : la boutique ne tient rien d'ouvert pendant que le workflow avance en face.

## 4. Faire tourner la démonstration

- [ ] 4.1 **Compter les processus avant de promettre.** La forme différée n'avance que si un worker
      de tâches de workflow poll du côté servant — les tests d'intégration les pilotaient à la main.
      La démonstration en demande donc, par maquette : un worker Nexus, un worker de workflow, un
      worker d'activité. **Six processus.** C'est la différence entre une démonstration qui tourne
      et une qui reste suspendue sans rien dire.
- [ ] 4.2 Un script unique qui démarre ce qu'il faut et raconte ce qui se passe, plutôt que six
      terminaux et un ordre à deviner.
- [ ] 4.3 Un README de la démonstration : les prérequis mesurés en 0.1 — PHP 8.3,
      `--ignore-platform-req=ext-curl` — et **le fait que les deux endpoints ne sont pas des
      résidus de test**. La suite d'intégration en crée d'éphémères sur le même cluster et les
      supprime ; ceux-ci sont stables, nommés `demo-*`, et personne ne doit les « nettoyer ».

## 5. Le dire

- [ ] 5.1 Une page de documentation, ou une section, qui montre la démonstration plutôt que de la
      décrire — c'est la première fois que deux applications Durable se parlent.
- [ ] 5.2 Corriger au passage la citation de `sylius/config/packages/durable.yaml`, qui invoque
      DUR035 (`await()` et les conditions) là où la décision est DUR037.
