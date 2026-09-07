---
title: Quatre applications qui s'appellent
weight: 10
---

# Quatre applications qui s'appellent

## Le problème

Une commande traverse quatre systèmes qui n'appartiennent pas à la même équipe : la boutique retient
le stock, le métier facture, la logistique planifie et expédie, l'ERP suit. Chacun a son dépôt, son
framework, son rythme de déploiement. Aucun n'a envie d'importer le code d'un autre.

La façon habituelle de coudre ça — une API HTTP par service, un client par appelant, un retry par
client, un timeout par retry — marche jusqu'au jour où l'un des quatre est éteint pendant la
transaction. Alors quelqu'un doit décider si on attend, si on rejoue, et ce qu'il advient de ce qui
a déjà été pris.

## Ce qui est construit

Quatre applications, quatre namespaces Temporal, trois frameworks. Elles vivent dans le dépôt, sous
[`sylius/`](https://github.com/gplanchat/durable-dev/tree/main/sylius),
[`symfony/`](https://github.com/gplanchat/durable-dev/tree/main/symfony),
[`magento/`](https://github.com/gplanchat/durable-dev/tree/main/magento) et
[`laravel/`](https://github.com/gplanchat/durable-dev/tree/main/laravel).

| | la boutique | le métier | le banc Magento | la logistique |
|---|---|---|---|---|
| framework | Sylius | Symfony | Mage-OS | Laravel |
| sert | `stock` | `billing` | — | `delivery` |
| appelle | `billing` | `stock` | les trois | `stock`, **depuis le workflow qui sert** |
| PHP | 8.3 | 8.3 | 8.2 | 8.2 |

Les quatre lisent le même paquet de contrats, `src/DurableDemoContracts/`. **Rien d'autre ne circule
entre elles** : pas de client HTTP, pas de SDK partagé, pas de classe d'implémentation.

## Ce que Durable apporte

**Appeler ne demande rien.** `WorkflowEnvironment::nexusStub()` lit le contrat par réflexion.
Servir se câble une fois par hôte — et se câble *hors* de Symfony : la logistique enregistre ses
gestionnaires avec deux classes et six lignes de `config/durable.php`, le banc Magento câble en
`di.xml`. La moitié servante de Nexus n'est pas une fonctionnalité du bundle.

**Les deux formes s'écrivent pareil.** `OrderWorkflow` appelle `verify` puis `charge` sur le
même stub. La première revient en quelques millisecondes, servie par une méthode ordinaire ; la
seconde prend une quinzaine de secondes, remplie par un workflow d'en face. **Le code de l'appelant
ne distingue pas les deux**, et c'est tout le sujet.

**L'attente ne tient rien d'ouvert.** Pendant une mise au point, le worker qui devait faire avancer
l'encaissement est resté éteint quatre minutes. L'opération est restée en
`NEXUS_OPERATION_STARTED`, l'appelant n'a rien consommé, et tout s'est terminé normalement quand le
worker est revenu. Aucune connexion, aucun processus, aucune transaction n'attendait. Refait depuis
Magento : 49 secondes, même résultat.

## Lue comme une carte de contextes

L'architecture explicite interdit l'appel synchrone entre contextes, et la raison qu'elle en donne
est la disponibilité : B tombe, A échoue, donc elle passe par des événements et de la cohérence à
terme. La mesure des quatre minutes ci-dessus retire cette raison-là. Elle laisse toutes les autres
debout.

Ce que ces quatre applications rendent opérationnel, c'est le vocabulaire stratégique :

| DDD | ce que c'est ici |
|---|---|
| Contexte borné | un namespace Temporal, avec ses workers, son stockage et son rythme de livraison |
| Langage publié | un contrat `#[AsNexusService]` et ses méthodes `#[AsNexusOperation]` |
| Relation de la carte | un **endpoint** Nexus, créé par un opérateur, qui pointe un nom vers un namespace et une file |
| Sens de la relation | quel côté a un endpoint, tout simplement |

Quatre namespaces, **trois endpoints**. Un endpoint dit où un service est servi : le banc Magento —
qui appelle trois services et n'en sert aucun — figure donc sur la carte avec des flèches qui en
partent et aucune qui y arrive. La carte de contextes, c'est `temporal operator nexus endpoint list`.

**Neuf secondes décident de la forme d'une opération.** Une tâche de démarrage porte
`request-timeout=8.998s`, qui borne la réponse à *cette tâche* et non l'opération ; passé ce délai
la tâche est redélivrée et le gestionnaire recommence. Une méthode implémentée est donc une
**requête qui traverse la frontière** — `verify` applique des règles de facturation à des données
que le métier a déjà — et tout ce qui dure plus longtemps est une **étape de saga** que l'autre
contexte possède, ce que déclare `#[FulfilsNexusOperation]`. L'appelant lit un seul contrat et ne
sait pas laquelle des deux il a obtenue.

**Des événements auraient coûté une corrélation.** Nexus en a une — l'identifiant du workflow qui
remplit l'opération *est* le jeton de celle-ci — mais c'est le serveur qui la tient, et ce qui livre
la réponse est le callback attaché au démarrage. Modélisez le même échange en deux événements et cet
identifiant devient le vôtre : à inventer, à stocker, à faire expirer, et à envelopper dans une
machine à états qui tient l'attente.

## Ce qu'il n'apporte pas

**Pas la compensation.** Aucun des trois contrats n'a d'opération qui rende ce qu'il a pris. La
seule protection est **l'ordre des appels** : `OrderNexusWorkflow` demande d'abord tout ce qui
peut dire non — vérifier la facture, planifier la tournée, retenir le stock — et n'engage
qu'ensuite. Les deux ordres inverses ont été écrits d'abord et mesurés : une commande en USD
retenait le stock avant de se faire refuser la facture, et une commande de six colis était
**encaissée** avant que la logistique ne refuse de la porter.

**Pas l'idempotence.** Une tâche Nexus est redélivrée ; c'est le gestionnaire qui doit tenir. Celui
de `stock` écrit son verdict dans `app_durable_stock_reservation`, clé par identifiant de commande —
rejouer la même commande rend le même verdict et ne retient pas de stock une seconde fois. Ça a
été écrit à la main, Durable ne l'a pas fourni.

**Pas la couche anticorruption.** `OrderWorkflow` lit `$verdict['accepted']` directement sur le
stub : la forme de charge d'un autre contexte se retrouve donc au cœur de la décision de la
boutique. La démonstration est plate à dessein, pour montrer les deux formes d'opération côte à
côte. Une application garderait le stub dans un adaptateur secondaire, déclarerait son port dans son
propre langage, et laisserait cet adaptateur fabriquer ses objets-valeurs : les contrats portent des
scalaires et des tableaux parce que le fil est du JSON nu, et il faut bien que quelqu'un en fasse un
modèle.

**Pas un noyau partagé petit.** `src/DurableDemoContracts/` en est un, et ce qui le rend tenable est
une règle plutôt qu'un mécanisme — il porte des noms d'opération et des formes de charge, et aucun
type de domaine de l'un ou l'autre côté.

## Comment on la lance

```bash
bin/demo-nexus        # d'abord : les namespaces et les endpoints Nexus
demo/run.sh           # ensuite : les huit workers
demo/run.sh --status  # dit qui tourne
demo/run.sh --stop    # les arrête
```

`bin/demo-nexus` passe en premier, et il n'est pas facultatif : les workers se connectent à des
endpoints qui n'existent pas tant qu'il ne les a pas créés. Les deux scripts impriment, une fois
finis, les commandes d'appel avec les bonnes valeurs.

L'ordre de démarrage n'a pas d'importance : un worker en retard fait attendre, il ne fait pas
échouer.

Deux prérequis qui ne se devinent pas, et que
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md) détaille :

- **un serveur Temporal dont les API Nexus sont actives.** `temporal server start-dev` convient ;
  `temporalio/auto-setup:1.25.2` répond `Nexus APIs are disabled` à la création d'endpoint ;
- **deux binaires PHP.** 8.3 pour les deux maquettes Symfony, 8.2 pour Magento et Laravel — c'est
  mesuré, pas frileux : sur le poste de référence aucune version unique n'a l'intersection des
  extensions exigées.

## Ce qui n'est pas prouvé

- **La montée en charge.** Quatre applications sur un poste, un serveur `start-dev`, une commande à
  la fois. Rien ici ne dit ce que fait une file Nexus sous charge réelle.
- **La reprise après un échec du gestionnaire servant.** Ce qui a été mesuré, c'est un worker
  *éteint* — pas un gestionnaire qui lève au milieu de son travail.
- **La forme en couches.** Tous les appels sont écrits depuis du code de workflow, sur un stub.
  Rien dans le dépôt ne démontre l'arrangement port/adaptateur que la section ci-dessus recommande.
- **La sécurité.** Les quatre namespaces sont sur le même serveur sans mTLS ni autorisation. Le
  cloisonnement inter-équipes, qui est la moitié de l'argument Nexus, n'est pas démontré.

Le détail de ce que chaque maquette a ajouté, maquette par maquette, est dans
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md). La mécanique
Nexus elle-même est décrite dans [Opérations Nexus](../../nexus/).
