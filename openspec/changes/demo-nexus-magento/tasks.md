# Tasks

## 0. Sonder avant de construire

- [x] 0.1 **Le PHP du banc Magento porte `ext-grpc`, et ce n'est pas le même que celui des deux
      autres maquettes.** Le banc n'a pas de conteneur PHP : son `compose.yaml` ne monte que MySQL,
      OpenSearch, Redis et une grappe Temporal, et `bin/magento` tourne sur le PHP de l'hôte.
      Remesuré sur le poste de référence :

      | PHP | grpc | curl | intl | pdo_mysql | soap |
      |---|---|---|---|---|---|
      | **8.2** | ✅ | ✅ | ✅ | ✅ | ✅ |
      | 8.3 | ✅ | ❌ | ✅ | ❌ | ❌ |
      | 8.4 | ❌ | ✅ | ❌ | ❌ | ❌ |

      Le tableau de §0.1 du change à deux applications concluait à un chemin étroit parce que la
      maquette Sylius **exige** PHP ≥ 8.3 ; le banc Magento est épinglé sur Mage-OS 2.2.0, qui
      accepte 8.2 — la seule version du poste qui ait tout. La démonstration tourne donc sur **deux
      binaires PHP**, et `demo/lancer.sh` porte `PHP` et `PHP_MAGENTO` séparément. Une seule version
      ne fait pas tourner les trois maquettes.
- [x] 0.2 **Appeler est sans hôte, et c'est mesuré et non lu.** Lu d'abord :
      `WorkflowEnvironment::nexusStub()` résout le contrat par réflexion et ne touche aucun
      conteneur, et `WorkflowTaskRunner` est la même classe pour les trois hôtes
      (`TemporalJournalTransport`, `RuntimeFactory::journalWorker()`, `DurableServiceProvider`).
      Mesuré ensuite, en §5 : aucune ligne n'a été ajoutée au cœur, au pont Temporal ni à
      `gplanchat/durable-magento` pour que la maquette appelle.

      ⚠ **La panne à connaître, parce que son message accuse le mauvais côté.** Les deux premiers
      essais ont échoué sur
      `No Nexus handler is registered for operation "verifier" of service "facturation"` —
      `NOT_IMPLEMENTED`, non réessayable, rendu par le worker Nexus du métier. La cause du premier
      était **chez l'appelant** : `symfony/vendor` n'avait pas `gplanchat/durable-demo-contracts`,
      son `composer install` datant d'avant le change à deux applications. Le message nomme le
      gestionnaire, l'endpoint et l'opération — tout sauf l'installation qui manque.
      Le second échec est survenu juste après un redémarrage des workers, sur un appel identique à
      ceux qui ont réussi ensuite (MAG-3, MAG-4, y compris après un redémarrage suivi d'une attente
      de cinq secondes). **La cause n'est pas établie** : le cache du conteneur avait été vidé par
      le `composer install`, donc l'explication « conteneur froid » ne tient pas. Ce qui est
      reproductible est le diagnostic, pas la panne : ce message-là veut dire *registre de
      gestionnaires sans l'entrée*, et il faut aller regarder les deux côtés.
- [x] 0.3 **La grappe du banc ne convient pas**, et le banc n'a pas eu à changer pour autant.
      `temporalio/auto-setup:1.25.2` répond `Nexus APIs are disabled`. Le DSN de la démonstration
      entre par `MAGENTO_DC_DURABLE__TEMPORAL__DSN`, la convention de Magento pour surcharger
      `app/etc/env.php` par l'environnement (`MAGENTO_DC_` + le chemin, `__` pour les `/`) —
      vérifiée dans `DeploymentConfig::getAllEnvOverrides()`, qui la pose **quelle que soit** la
      présence de la clé dans le fichier. `app/etc/env.php` n'est pas modifié, et il n'est pas
      versionné.

## 1. Le contrat partagé, troisième consommateur

- [x] 1.1 **Une entrée `autoload` plutôt qu'un dépôt path, et c'est une décision, pas un raccourci.**
      Les deux autres maquettes déclarent `gplanchat/durable-demo-contracts` en dépôt path. Le banc
      Magento y ajoute une ligne de `psr-4` vers `../src/DurableDemoContracts/`, pour trois raisons
      mesurées :

      1. ses trois dépôts path sont en `"symlink": false` — Composer **copie**, donc toute retouche
         du contrat exigerait un `composer update` du banc pour être vue, ce qui est exactement le
         piège qu'une démonstration ne doit pas tendre ;
      2. la CI n'installe pas ce banc : `magento-matrix` monte un projet jetable, et le lock
         d'ici n'est le gardien de rien ;
      3. `composer dump-autoload` suffit, en une seconde et sans réseau, là où un dépôt path
         demanderait une résolution complète du lock de Mage-OS.

      Le préfixe le plus long gagne en PSR-4 : `Gplanchat\Durable\Demo\Contracts\` est servi par
      cette entrée et non par le `Gplanchat\Durable\` du paquet du cœur. Vérifié —
      `interface_exists(StockContract::class)` rend `true` depuis l'autoload du banc.
- [x] 1.2 ⚠ **Le garde de nommage n'existe pas de ce côté, et n'a pas à y être.** La règle « tout
      paramètre de workflow sans valeur par défaut doit être un paramètre du contrat » vit dans
      `NexusHandlerPass`, donc dans le conteneur de Symfony. Elle garde le **servant**. Magento ne
      sert rien : ce qu'il doit tenir juste, ce sont les noms passés aux méthodes du stub, et la
      signature typée du contrat les vérifie déjà à l'écriture. Dit dans l'en-tête du workflow.

## 2. Le workflow appelant

- [x] 2.1 `CommandeNexusWorkflow` dans `Gplanchat_DurableProbe` — le module de banc, pas le paquet
      publié. Deux stubs, deux endpoints, trois opérations.

      **L'ordre des trois appels a changé après mesure**, et c'est le seul défaut de conception que
      la démonstration a trouvé. Écrit d'abord `reserver` puis `verifier`, il laissait un cas sans
      issue : `MAG-6`, en USD, a retenu `MUG_BLUE` chez la boutique **puis** s'est fait refuser la
      facture — et le contrat `stock` n'a pas d'opération qui rende ce qui a été pris. Vérifier
      d'abord fait qu'il n'y a rien à rendre : un refus de facture ne touche pas le stock, et un
      refus de stock ne touche pas l'argent, `encaisser` venant après les deux.
- [x] 2.2 `bin/magento durable:demo:nexus` démarre l'exécution **sur la grappe** par
      `RuntimeFactory::workflowClient()` : `MagentoRuntime::run()` l'aurait exécutée dans le
      processus de la commande, ce qui est le contraire de ce que la démonstration montre.
      Sa dernière ligne dit ce qui s'est passé et non ce qui se passe d'habitude — un refus revient
      en 0,3 s, et annoncer « dont l'encaissement » ferait passer un refus pour un encaissement
      anormalement rapide.
- [x] 2.3 Le workflow et la commande sont déclarés dans le `di.xml` du banc : le conteneur de
      Magento n'a pas les tags de Symfony, rien ne ramasse `#[AsWorkflow]` tout seul.

## 3. Le cluster

- [x] 3.1 `bin/demo-nexus` crée le namespace `demo-magento` et **aucun endpoint de plus**. Trois
      namespaces, deux endpoints : un endpoint dit où un service est servi, et Magento ne sert rien.
      Mesuré à la création : `+ namespace demo-magento`, et les deux endpoints inchangés.

## 4. Faire tourner la démonstration

- [x] 4.1 `demo/lancer.sh` démarre le worker de journal du banc. **Six processus, et non sept** :
      `CommandeNexusWorkflow` n'a pas d'activité — tout ce qu'il fait est servi ailleurs —, donc pas
      de worker d'activité, exactement comme la boutique et pour la même raison.
      La fonction `demarrer` s'est dédoublée en `lancer` (une commande quelconque) et `demarrer`
      (un `messenger:consume` dans un `APP_ENV`) : les trois maquettes ne tournent pas leurs workers
      de la même façon, et la différence tient dans un argument.
- [x] 4.2 Le script imprime le troisième appel avec les bonnes valeurs, `PHP_MAGENTO` compris.

## 5. Éprouver

- [x] 5.1 **Trois processus PHP par-dessus six workers, trois namespaces, et les quatre cas.**
      Banc : `temporal server start-dev --port 7239`, PostgreSQL pour la boutique, MySQL pour
      Magento, `MUG_BLUE` à 5 en stock et `MUG_RED` à 1.

      | appel | réponse | durée | effet |
      |---|---|---|---|
      | `MAG-20 1200 MUG_BLUE=2` | `verifiee.acceptee: true`, `reserve: true`, `recu: RECU-EUR-MAG-20` | 13,9 s | `on_hold` 0 → 2 |
      | `MAG-11 4200 MUG_BLUE=1 --devise=USD` | `acceptee: false`, `motif: devise USD non prise en charge`, `reservation: null` | 0,3 s | rien — le stock n'est même pas demandé |
      | `MAG-12 1200 MUG_RED=3` | `acceptee: true`, `reserve: false`, `manquants: {MUG_RED: 2}` | 0,4 s | rien, et rien d'encaissé |
      | `MAG-21 1200 MUG_BLUE=1`, worker d'en face **éteint 49 s** | résultat identique au nominal | 49,6 s | `on_hold` +1 |

      La dernière ligne est la preuve que la démonstration à deux applications avait obtenue par
      accident, refaite exprès depuis Magento : `metier-workflows` éteint, l'opération est restée en
      `NexusOperationStarted` pendant quarante secondes sans que rien n'avance, et tout s'est terminé
      normalement au retour du worker. Magento ne tenait ni connexion, ni processus, ni transaction.

      ⚠ **Une fausse piste, écartée à la lecture du payload brut.** Le CLI Temporal affiche
      `result.manquants: <nil>` et `"manquants":null` dans sa vue aplatie, là où la commande imprime
      `[]`. Le payload stocké dit `{"reservation":{"reserve":true,"manquants":[]}}` : c'est bien le
      `[]` que §2.6 du change précédent documente déjà — un tableau associatif vide encodé en liste —,
      et non une troisième forme. Rien à corriger, mais la vue du CLI ment sur ce point.
- [x] 5.2 **Les événements Nexus sont dans le journal de Magento**, ce qui lève la réserve de
      `magento-module` §3bis.9. `durable-MAG-20`, 24 événements :

      | # | événement | ce qu'il porte |
      |---|---|---|
      | 5 → 6 | `NexusOperationScheduled` → `Completed` | `facturation` / `verifier`, réglé sur la tâche |
      | 10 → 11 | `NexusOperationScheduled` → `Completed` | `stock` / `reserver`, réglé sur la tâche |
      | 15 → 16 → 20 | `Scheduled` → `Started` → `Completed` | `demo-metier-facturation`, `facturation` / `encaisser`, **13 s entre le 16 et le 20** |

      L'événement 15 nomme `endpoint`, `service`, `operation` et la charge ; le 16 porte un
      `operationToken` et **rien d'autre** — c'est l'état « en vol », celui qu'un tableau de bord
      doit savoir distinguer d'un échec. Le montrer appartient à `change/dashboard-presentation` ;
      le produire appartenait à ici.

## 6. Le dire

- [x] 6.1 `demo/README.md` : trois maquettes, six processus, le troisième namespace, et les
      prérequis du banc Magento.
- [x] 6.2 `documentation/user/nexus/_index.{md,fr.md}` : la section devient « Trois applications, en
      vrai », suivie de « Appeler ne demande rien à votre hôte » — l'asymétrie appelant/servant, les
      trois namespaces pour deux endpoints, et l'ordre des appels comme compensation.
      Vérifié sur un build `--minify` servi en HTTP, les deux langues symétriques : **8 `h2`,
      6 `h3`, 2 tableaux, 13 blocs de code** de chaque côté, et le tableau des maquettes rend bien
      ses quatre colonnes.
- [x] 6.3 La réserve de `EveryCaseWorkflow` et le `README.md` du banc renvoient désormais au
      workflow qui existe, au lieu d'annoncer un change à venir.
