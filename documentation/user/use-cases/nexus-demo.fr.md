---
title: Quatre applications qui s'appellent
weight: 10
---

# Quatre applications qui s'appellent

## Le problème

Une commande traverse quatre systèmes qui n'appartiennent pas à la même équipe : la boutique retient
le stock, le métier facture, la logistique planifie et expédie, l'ERP suit. Chacun a son dépôt, son
framework, son rythme de déploiement. Aucun n'a envie d'importer le code d'un autre.

La façon habituelle de relier ces systèmes (une API HTTP par service, un client par appelant, un
retry par client, un timeout par retry) marche jusqu'au jour où l'un des quatre est éteint pendant
la transaction. Quelqu'un doit alors décider s'il faut attendre, s'il faut rejouer, et ce que
devient ce qui a déjà été pris.

## Ce qui est construit

Quatre applications, quatre namespaces Temporal, trois frameworks. Elles vivent dans le dépôt, sous
[`sylius/`](https://github.com/gplanchat/durable-dev/tree/main/sylius),
[`symfony/`](https://github.com/gplanchat/durable-dev/tree/main/symfony),
[`magento/`](https://github.com/gplanchat/durable-dev/tree/main/magento) et
[`laravel/`](https://github.com/gplanchat/durable-dev/tree/main/laravel).

Le tableau liste les opérations Nexus que chaque application sert et appelle. Une opération Nexus
est une opération servie par un autre service, avec son propre contrat, qu'un workflow appelle
comme il appelle une activité (voir le [glossaire](../../glossary/)).

| | la boutique | le métier | le banc Magento | la logistique |
|---|---|---|---|---|
| framework | Sylius | Symfony | Mage-OS | Laravel |
| sert | `stock` | `billing` | rien | `delivery` |
| appelle | `billing` | `stock` | les trois | `stock`, **depuis le workflow qui sert** |
| PHP | 8.3 | 8.3 | 8.2 | 8.2 |

Les quatre lisent le même paquet de contrats, `src/DurableDemoContracts/`. **Rien d'autre ne circule
entre elles** : pas de client HTTP, pas de SDK partagé, pas de classe d'implémentation.

## Ce que Durable apporte

**Appeler une opération ne demande aucun câblage.** `WorkflowEnvironment::nexusStub()` lit le
contrat par réflexion. Servir se câble une fois par hôte, y compris *hors* de Symfony : la logistique enregistre ses
gestionnaires avec deux classes et six lignes de `config/durable.php`, le banc Magento câble en
`di.xml`. La moitié servante de Nexus n'est pas une fonctionnalité du bundle.

**Les deux formes s'écrivent pareil.** `OrderWorkflow` appelle `verify` puis `charge` sur le
même stub. La première revient en quelques millisecondes, servie par une méthode ordinaire ; la
seconde prend une quinzaine de secondes, remplie par un workflow d'en face. **Le code de l'appelant
est le même pour les deux.**

**L'attente ne tient rien d'ouvert.** Pendant une mise au point, le worker qui devait faire avancer
l'encaissement est resté éteint quatre minutes. L'opération est restée en
`NEXUS_OPERATION_STARTED`, l'appelant n'a rien consommé, et tout s'est terminé normalement quand le
worker est revenu. Aucune connexion, aucun processus ni aucune transaction n'attendait. Le même essai
depuis Magento, avec 49 secondes d'arrêt, a donné le même résultat.

## Lue comme une carte de contextes

L'architecture explicite interdit l'appel synchrone entre contextes, et la raison qu'elle en donne
est la disponibilité : B tombe, A échoue, donc elle recommande des événements et de la cohérence à
terme. La mesure des quatre minutes ci-dessus retire cette raison-là. Les autres raisons restent
valables.

Les quatre applications donnent à chaque terme du vocabulaire stratégique du DDD un équivalent
opérationnel.

| DDD | ce que c'est ici |
|---|---|
| Contexte borné | un namespace Temporal, avec ses workers, son stockage et son rythme de livraison |
| Langage publié | un contrat `#[AsNexusService]` et ses méthodes `#[AsNexusOperation]` |
| Relation de la carte | un **endpoint** Nexus, créé par un opérateur, qui pointe un nom vers un namespace et une file |
| Sens de la relation | quel côté a un endpoint |

La démonstration compte quatre namespaces et **trois endpoints**. Un endpoint dit où un service est
servi. Le banc Magento, qui appelle trois services et n'en sert aucun, figure donc sur la carte avec
des flèches qui en partent et aucune qui y arrive. `temporal operator nexus endpoint list` affiche
la carte de contextes.

**Une tâche de démarrage dispose de neuf secondes.** Une tâche de démarrage porte
`request-timeout=8.998s`, qui borne la réponse à *cette tâche* et non l'opération ; passé ce délai
la tâche est redélivrée et le gestionnaire recommence. Une méthode implémentée est donc une
**requête qui traverse la frontière**, et `verify` applique des règles de facturation à des données
que le métier a déjà. Tout ce qui dure plus longtemps est une **étape de saga** que l'autre
contexte possède, ce que déclare `#[FulfilsNexusOperation]`. L'appelant lit un seul contrat, et
son code est le même dans les deux cas.

**Des événements demanderaient un identifiant de corrélation.** Nexus en a un : l'identifiant du workflow qui
remplit l'opération *est* le jeton de celle-ci. C'est le serveur qui le tient, et ce qui livre la
réponse est le callback attaché au démarrage. Si vous modélisez le même échange en deux événements,
c'est à vous d'inventer cet identifiant, de le stocker, de le faire expirer et de l'envelopper dans
une machine à états qui tient l'attente.

## Ce qu'il n'apporte pas

**Durable ne fournit pas la compensation.** Aucun des trois contrats n'a d'opération qui rende ce qu'il a pris. La
seule protection est **l'ordre des appels** : `OrderNexusWorkflow` demande d'abord tout ce qui
peut dire non (vérifier la facture, planifier la tournée, retenir le stock) et n'engage
qu'ensuite. Les deux ordres inverses ont été écrits d'abord et mesurés : une commande en USD
retenait le stock avant de se faire refuser la facture, et une commande de six colis était
**encaissée** avant que la logistique ne refuse de la porter.

**Durable ne fournit pas l'idempotence.** Une tâche Nexus est redélivrée, et le gestionnaire doit le supporter. Celui
de `stock` écrit son verdict dans `app_durable_stock_reservation`, clé par identifiant de commande :
rejouer la même commande rend le même verdict et ne retient pas de stock une seconde fois. Cette
table est écrite à la main.

**Durable ne fournit pas de couche anticorruption.** Vous l'écrivez vous-même, et la boutique en a désormais une.
`OrderWorkflow` invoque un cas d'usage `PlaceOrder` à travers un port `Payments`. `NexusPayments`
implémente le port et passe la charge de `verify` à `Authorisation::fromWire()`, la seule méthode
de `sylius/src/` qui lise son champ `accepted`. Les trois bancs qui appellent sans cette couche montrent ce que coûte de s'en passer : `OrderNexusWorkflow`
lit cinq charges par clé, dans le code qui décide. Les contrats portent des scalaires et des
tableaux parce que le fil est du JSON nu, et il faut que quelque chose en fasse un modèle.

**Aucun mécanisme ne limite la taille du noyau partagé.** `src/DurableDemoContracts/` est un petit noyau partagé, et ce qui le rend tenable est
une règle plutôt qu'un mécanisme : il porte des noms d'opération et des formes de charge, et aucun
type de domaine de l'un ou l'autre côté.

## Comment la lancer {#comment-on-la-lance}

```bash
bin/demo-nexus        # d'abord : les namespaces et les endpoints Nexus
demo/run.sh           # ensuite : les huit workers
demo/run.sh --status  # dit qui tourne
demo/run.sh --stop    # les arrête
```

Lancez `bin/demo-nexus` en premier. Il est obligatoire, parce que les workers se connectent à des
endpoints qui n'existent pas tant qu'il ne les a pas créés. Une fois terminés, les deux scripts
affichent les commandes d'appel avec les bonnes valeurs.

L'ordre de démarrage des workers n'a pas d'importance. Un worker en retard fait attendre
l'exécution sans la faire échouer.

Deux prérequis ne sont pas évidents.
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md) les détaille :

- **un serveur Temporal dont les API Nexus sont actives.** `temporal server start-dev` convient ;
  `temporalio/auto-setup:1.25.2` répond `Nexus APIs are disabled` à la création d'endpoint ;
- **deux binaires PHP.** 8.3 pour les deux applications Symfony, 8.2 pour Magento et Laravel. Ce
  partage vient de la mesure : sur le poste de référence, aucune version de PHP n'a toutes les
  extensions exigées.

## Ce qui n'est pas prouvé

- **La montée en charge.** Quatre applications sur un poste, un serveur `start-dev`, une commande à
  la fois. Rien ici ne dit ce que fait une file Nexus sous charge réelle.
- **La reprise après un échec du gestionnaire servant.** Ce qui a été mesuré, c'est un worker
  *éteint*, pas un gestionnaire qui lève au milieu de son travail.
- **La forme en couches, ailleurs que dans la boutique.** `sylius/` a son port, son adaptateur et
  son cas d'usage. Le banc Magento et le banc logistique appellent toujours des stubs depuis du
  code de workflow : l'arrangement est démontré une fois, pas éprouvé sur plusieurs hôtes.
- **La sécurité.** Les quatre namespaces sont sur le même serveur sans mTLS ni autorisation. Le
  cloisonnement inter-équipes, qui est la moitié de l'argument Nexus, n'est pas démontré.

[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md) détaille ce
que chaque application a ajouté, une par une. [Opérations Nexus](../../nexus/) décrit la mécanique
Nexus elle-même.
