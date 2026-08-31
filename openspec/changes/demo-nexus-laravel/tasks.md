# Tasks

## 0. Sonder avant de construire

- [x] 0.1 **Quelle ligne de Laravel, et sur quel PHP.** Le banc doit atteindre la grappe, donc
      `ext-grpc`, donc PHP 8.2 — la seule version du poste à l'avoir avec `pdo_sqlite`, que Laravel
      veut par défaut. Plutôt que de raisonner sur les contraintes, on laisse la résolution
      trancher : `php8.2 composer create-project laravel/laravel` rend **Laravel 12.68**, sur
      `php: ^8.2`. Laravel 13 exige 8.3 et est donc hors d'atteinte du banc, comme Mage-OS 3 l'est
      du banc Magento, et pour la même raison.
- [x] 0.2 **Le paquet tient-il ce qu'il promet côté servant ?** Lu avant d'écrire :
      `DeclaredNexusOperations` enregistre les gestionnaires déclarés dans `config/durable.php`,
      lit `#[FulfilsNexusOperation]` sur les workflows déclarés, et passe par
      `NexusContractResolver` et `NexusHandlerInvoker` — les mêmes classes que la passe Symfony.
      `durable:nexus-worker` existe et poll.

      ⚠ **Les deux commandes de worker ne sont enregistrées que par le backend `temporal`** — leur
      `$this->commands([...])` est à la fin de `bindTemporal()`. Sur le backend `illuminate` par
      défaut, `artisan list` ne montre rien et le message d'erreur est « command not defined », qui
      ne nomme ni le backend ni la config. Mesuré : `backend => 'temporal'` les fait apparaître
      toutes les deux.

      ⚠ **Le README du paquet dit le contraire de ce que le paquet fait.** Sa section « Not in this
      package » annonce « **Temporal.** Serving it from here is not decided, and this package
      refuses the combination by name until it is », alors que `BACKENDS` contient `'temporal'`, que
      `bindTemporal()` existe, et que `composer.json` suggère le pont. La phrase date d'avant la
      tranche qui l'a écrit. Corrigée ici, puisque c'est ce chantier qui la met en défaut.
- [x] 0.3 **Ce qu'aucune passe ne garde sur cet hôte.** `NexusHandlerPass` refuse le conteneur
      Symfony quand un paramètre d'un workflow remplissant ne porte pas le nom déclaré par le
      contrat. `DeclaredNexusOperations` n'a pas d'équivalent : il enregistre, il ne compare pas.
      Sur cet hôte, un renommage d'un seul côté donne `null` au workflow, sans erreur et sans trace.
      **Dit à trois endroits** — le contrat, le workflow, le README du banc — et **pas corrigé
      ici** : ajouter un garde au paquet publié est un change à lui seul, et il n'appartient pas à
      une démonstration.

## 1. Le contrat `livraison`

- [x] 1.1 Deux interfaces, comme les deux autres contrats : `LivraisonServed` (`planifier`,
      immédiate) et `LivraisonContract extends LivraisonServed` (`expedier`, remplie par un
      workflow). Scalaires et tableaux seulement — la charge est du JSON, et un paramètre typé objet
      y recevrait un tableau.

## 2. La maquette

- [x] 2.1 `laravel/` : `composer create-project laravel/laravel`, dépôts path vers les cinq paquets
      du dépôt, `vendor/` ignoré. Les fichiers du squelette qui ne parlent pas de la démonstration
      — `.github/workflows/` de `laravel/laravel`, `CHANGELOG.md`, `.styleci.yml` — sont retirés :
      la maquette `symfony/` ne garde que son README, et c'est le précédent à suivre plutôt que
      celui de `sylius/`, qui a gardé les siens.
- [x] 2.2 `LivraisonHandler` répond à `planifier` et **est idempotent** : une tâche Nexus est
      redélivrée, et un créneau tiré deux fois n'est pas le même. Le cache porte l'idempotence, avec
      son plafond nommé dans un commentaire `ponytail:` — une vraie logistique la rangerait dans sa
      table d'expéditions, et c'est là qu'il faudra la mettre le jour où planifier consomme une
      capacité.
- [x] 2.3 `ExpedierWorkflow` remplit `expedier`, dort six secondes, **puis appelle
      `stock/reserver`** chez la boutique. L'appel est sans risque parce que `reserver` est
      idempotente par identifiant de commande : lignes vides, la boutique relit la décision au lieu
      d'en prendre une nouvelle.
- [x] 2.4 Six lignes de `config/durable.php`, et rien d'autre : pas de provider, pas de commande,
      pas de passe. C'est ce que la maquette existe pour montrer.

## 3. Le cluster et les processus

- [x] 3.1 `bin/demo-nexus` : quatre namespaces, **trois** endpoints. La maquette Laravel sert, donc
      elle a le sien ; la maquette Magento n'en a toujours pas, et l'avertissement du script dit
      maintenant les deux cas côte à côte.
- [x] 3.2 `demo/lancer.sh` : huit processus. Deux pour la logistique — un worker Nexus et un worker
      de tâches de workflow —, et **pas trois** : `ExpedierWorkflow` n'a pas d'activité.
      `PHP_LARAVEL` s'ajoute à `PHP_MAGENTO`, même défaut `php8.2`, deux variables parce que les
      deux bancs n'ont aucune raison de rester sur la même version pour toujours.

## 4. Le workflow appelant du banc Magento

- [x] 4.1 Cinq appels, trois endpoints : `verifier`, `planifier`, `reserver`, `encaisser`,
      `expedier`.

      **L'ordre a encore changé, et pour la même raison qu'au chantier précédent.** Écrit d'abord
      vérifier → réserver → encaisser → planifier → expédier, il faisait **payer** une commande que
      la logistique refusait ensuite de porter — `LAR-12`, six colis pour cinq au plus par tournée.
      Aucun des trois contrats n'a d'opération qui rende ce qu'il a pris. La règle est donc
      explicite dans le code : **demander d'abord tout ce qui peut dire non, n'engager qu'ensuite**.
- [x] 4.2 ⚠ **Une union de tableaux a failli avaler un verdict.** `self::rien($verdict) +
      ['reservation' => $reservation]` garde la clé **de gauche** — donc le `null` — et le verdict
      de stock aurait disparu du résultat en silence. `array_merge` corrige, et le commentaire dit
      pourquoi.

## 5. Éprouver

- [x] 5.1 **Quatre applications, quatre namespaces, huit workers, trois frameworks.**

      | appel | issue | durée |
      |---|---|---|
      | `LAR-22 1200 MUG_BLUE=2` | vérifiée, planifiée, réservée, encaissée, **expédiée** `TRK-6C1719B66B` | 19,8 s |
      | `LAR-11 4200 MUG_BLUE=1 --devise=USD` | refusée à la facture — `reservation`, `livraison` et `expedition` restent `null` | 0,3 s |
      | `LAR-12 1500 MUG_BLUE=5 MUG_RED=1` | **refusée par la logistique** — « 6 colis, 5 au plus par tournée » —, rien de réservé, rien d'encaissé | 0,3 s |
      | `LAR-13 1200 MUG_RED=3` | planifiée, puis refusée au stock — rien d'encaissé | 0,7 s |

      La ligne `LAR-12` est celle qui valait le réordonnancement de §4.1 : avant lui, elle
      encaissait 1500 centimes pour une tournée que personne n'allait faire.
- [x] 5.2 **L'historique de `durable-LAR-22`**, 38 événements, nomme les trois endpoints dans une
      seule exécution :

      | # | opération | endpoint | forme |
      |---|---|---|---|
      | 5 → 6 | `facturation` / `verifier` | `demo-metier-facturation` | sur la tâche |
      | 10 → 11 | `livraison` / `planifier` | `demo-laravel-livraison` | sur la tâche |
      | 15 → 16 | `stock` / `reserver` | `demo-boutique-stock` | sur la tâche |
      | 20 → 21 → 25 | `facturation` / `encaisser` | `demo-metier-facturation` | workflow, 13 s |
      | 29 → 30 → 34 | `livraison` / `expedier` | `demo-laravel-livraison` | workflow, 6 s |
- [x] 5.3 **Et l'exécution qui sert **et** appelle**, côté logistique. Son identifiant est
      `nexus-5d4c17c12e38bdd2` : un workflow démarré pour remplir une opération porte le **jeton de
      l'opération** comme identifiant, et non un nom choisi par l'application.

      ```
       5  TimerStarted              ← les six secondes de préparation
       6  TimerFired
      10  NexusOperationScheduled   ← stock/reserver, chez la boutique
      11  NexusOperationCompleted
      15  WorkflowExecutionCompleted
      ```

      Quinze événements, dont une opération servie (l'exécution elle-même) et une opération appelée.
      C'est la scénario 3 de la spec, et il tourne.
- [x] 5.4 ⚠ **Un piège de banc, trouvé en mesurant et qui n'est pas un défaut du produit.** Deux
      relevés de stock ont été incohérents — `on_hold` à 4 puis à 5 pour des commandes de 2 et de 1.
      La cause n'est pas la démonstration : le worker Nexus de la boutique est un processus **long**,
      son `EntityManager` garde les `ProductVariant` dans sa carte d'identité, et un
      `UPDATE … SET on_hold = 0` passé en SQL sous ses pieds lui est **invisible** — il réécrit
      ensuite l'ancienne valeur augmentée. Après redémarrage du worker, la même commande donne
      exactement le delta attendu : `MUG_BLUE` 0 → 2 pour `LAR-22`. Réinitialiser le stock demande
      donc de redémarrer le worker, et `demo/README.md` le dit maintenant.

## 6. Le dire

- [x] 6.1 `demo/README.md` : quatre maquettes, huit processus, le tableau des rôles, le piège de
      §5.4 et les prérequis du banc Laravel.
- [x] 6.2 `documentation/user/nexus/_index.{md,fr.md}` : la section devient « Quatre applications, en
      vrai », et la sous-section sur l'asymétrie dit désormais **les deux moitiés** — appeler ne
      demande rien, servir se câble une fois par hôte, et voici à quoi ressemble ce câblage hors de
      Symfony. Vérifié sur un build `--minify` servi en HTTP, les deux langues symétriques : **8 `h2`, 8 `h3`,
      2 tableaux, 15 blocs de code** de chaque côté, et le tableau des maquettes rend ses cinq
      colonnes.
- [x] 6.3 `src/DurableLaravel/README.md` : la phrase de §0.2 qui refusait Temporal est corrigée.
