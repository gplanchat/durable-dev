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
| sert | `stock` | `facturation` | — | `livraison` |
| appelle | `facturation` | `stock` | les trois | `stock`, **depuis le workflow qui sert** |
| PHP | 8.3 | 8.3 | 8.2 | 8.2 |

Les quatre lisent le même paquet de contrats, `src/DurableDemoContracts/`. **Rien d'autre ne circule
entre elles** : pas de client HTTP, pas de SDK partagé, pas de classe d'implémentation.

## Ce que Durable apporte

**Appeler ne demande rien.** `WorkflowEnvironment::nexusStub()` lit le contrat par réflexion.
Servir se câble une fois par hôte — et se câble *hors* de Symfony : la logistique enregistre ses
gestionnaires avec deux classes et six lignes de `config/durable.php`, le banc Magento câble en
`di.xml`. La moitié servante de Nexus n'est pas une fonctionnalité du bundle.

**Les deux formes s'écrivent pareil.** `CommandeWorkflow` appelle `verifier` puis `encaisser` sur le
même stub. La première revient en quelques millisecondes, servie par une méthode ordinaire ; la
seconde prend une quinzaine de secondes, remplie par un workflow d'en face. **Le code de l'appelant
ne distingue pas les deux**, et c'est tout le sujet.

**L'attente ne tient rien d'ouvert.** Pendant une mise au point, le worker qui devait faire avancer
l'encaissement est resté éteint quatre minutes. L'opération est restée en
`NEXUS_OPERATION_STARTED`, l'appelant n'a rien consommé, et tout s'est terminé normalement quand le
worker est revenu. Aucune connexion, aucun processus, aucune transaction n'attendait. Refait depuis
Magento : 49 secondes, même résultat.

## Ce qu'il n'apporte pas

**Pas la compensation.** Aucun des trois contrats n'a d'opération qui rende ce qu'il a pris. La
seule protection est **l'ordre des appels** : `CommandeNexusWorkflow` demande d'abord tout ce qui
peut dire non — vérifier la facture, planifier la tournée, retenir le stock — et n'engage
qu'ensuite. Les deux ordres inverses ont été écrits d'abord et mesurés : une commande en USD
retenait le stock avant de se faire refuser la facture, et une commande de six colis était
**encaissée** avant que la logistique ne refuse de la porter.

**Pas l'idempotence.** Une tâche Nexus est redélivrée ; c'est le gestionnaire qui doit tenir. Celui
de `stock` écrit son verdict dans `app_durable_stock_reservation`, clé par identifiant de commande —
rejouer la même commande rend le même verdict et ne retient pas de stock une seconde fois. Ça a
été écrit à la main, Durable ne l'a pas fourni.

## Comment on la lance

```bash
demo/lancer.sh            # démarre les huit workers
demo/lancer.sh --etat     # dit qui tourne
demo/lancer.sh --arreter  # les arrête
```

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
- **La sécurité.** Les quatre namespaces sont sur le même serveur sans mTLS ni autorisation. Le
  cloisonnement inter-équipes, qui est la moitié de l'argument Nexus, n'est pas démontré.

Le détail de ce que chaque maquette a ajouté, maquette par maquette, est dans
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md). La mécanique
Nexus elle-même est décrite dans [Opérations Nexus](../../nexus/).
