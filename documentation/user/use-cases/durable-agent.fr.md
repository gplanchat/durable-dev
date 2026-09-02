---
title: Un agent IA interruptible
weight: 20
---

# Un agent IA interruptible

> [!WARNING]
> **Prototype.** Le code décrit ici vit sur une branche qui n'est pas encore fusionnée, et il
> n'y a pas de chemin de démarrage documenté. Cette page publie **le motif**, pas un paquet : les
> quatre décisions ci-dessous s'appliquent à n'importe quel agent Symfony AI, avec ou sans le code
> du dépôt.

## Le problème

Un agent qui appelle des outils passe des minutes, parfois des heures, à travailler. Pendant ce
temps il fait des choses qui ne se défont pas : il envoie un courriel, il encaisse un paiement, il
pousse un prix en production.

Deux besoins se cognent. Le premier : **quelqu'un doit pouvoir dire non** avant l'appel dangereux,
et ce quelqu'un est en réunion — il répondra dans dix minutes, pas dans les 30 secondes d'un timeout
HTTP. Le second : **le processus va redémarrer.** Un déploiement, un OOM kill, une machine qui
tourne. Si l'agent avait passé sept appels d'outil sur neuf, on ne veut pas repayer les sept.

Le hook `ToolCallRequested::deny()` de Symfony AI répond au premier besoin tant que personne ne
redémarre : il est synchrone et in-process. `maxToolCalls` est un compteur en mémoire. Les deux
disparaissent avec le processus.

## Ce qui est construit

La boucle d'agent de Symfony AI, **pilotée depuis du code de workflow**. Une conversation est une
exécution de workflow ; chaque message de l'humain est un signal. Entre deux messages le workflow
n'attend pas : il est suspendu, et ne consomme rien.

L'agent lui-même n'est pas modifié. On compose un `Provider` normal avec deux implémentations à
nous :

| Couture | Ce qu'elle devient |
|---|---|
| `ModelClientInterface` | un `await` sur une activité — le seul HTTP de tout l'agent |
| `ToolExecutorInterface` | un `await` par appel d'outil, précédé de la garde |
| `ToolboxInterface` | un simple registre de schémas ; il n'exécute plus rien |

`Agent::call()` est appelé tel quel depuis le workflow. Il ne sait pas qu'il est rejouable.

## Les quatre décisions

C'est la partie réutilisable. Aucune ne demande de dépendance.

**1. La couture basse est `ModelClientInterface`, pas `PlatformInterface`.** C'est ce qui rend
l'exercice court. `Provider::invoke()` transforme la conversation en tableau plat *avant* d'atteindre
le client, et la réponse brute est du JSON. À cet endroit il n'y a donc **rien à traduire** : ni
`MessageBag`, ni `Content`, ni `Thinking`, ni `Metadata`. Se brancher un cran plus haut, sur
`PlatformInterface`, oblige à sérialiser tout l'arbre d'objets — pour le même résultat.

**2. Ce qui protège, c'est de classer les outils — pas d'avoir des modes.** Chaque outil porte un
`effect` : `read`, `write` ou `external`. Le mode courant ne fait que consulter cette table. Dire
« pousser un prix est `external`, pas `write` » est l'acte de design ; le mode n'est que sa
conséquence. Le défaut est prudent — un outil non classé compte pour `external` — mais ce n'est pas
une excuse pour ne pas classer.

**Et la plupart des outils ne méritent rien.** Un outil a besoin d'une sécurité d'exécution s'il
répond oui à au moins une de ces questions :

1. le rejouer deux fois fait-il du mal ? (facturer deux fois, envoyer deux courriels)
2. peut-il réussir alors qu'une étape suivante échouera ? — il lui faut une compensation
3. dure-t-il plus qu'une requête HTTP ? — minutes, heures, jours
4. quelqu'un doit-il l'autoriser ?

Quatre non — et c'est le cas de `chercher_produit`, `lire_stock`, `consulter_facture` — une activité
suffit. Tout envelopper fabrique le problème qu'on prétend résoudre.

**3. La clé d'idempotence vient du workflow, pas de l'outil.** Elle doit être déterministe au rejeu,
donc dérivée de l'identifiant d'exécution et de l'identifiant d'appel. Un outil qui fabrique sa
propre clé avec `uniqid()` casse le rejeu au premier redémarrage — et c'est le genre de panne qu'on
découvre en production.

**4. L'approbation est un signal, avec une échéance d'humain.** Pas un `deny()` synchrone. Et
l'échéance est celle de quelqu'un qui lit, réfléchit et change de fenêtre : le prototype est réglé à
quinze minutes. Il a d'abord été réglé à 120 secondes, et la carte de validation disparaissait sous
les yeux de la personne qui la lisait — l'agent répondait « refusé faute de validation » sans que
personne n'ait rien refusé.

## Ce que Durable apporte

- **Une approbation humaine qui survit au redémarrage.** Un workflow qui attend trois jours un
  signal d'accord est une autre classe de chose qu'un hook in-process.
- **Une compensation saga sur les outils non idempotents.** L'agent qui a envoyé le courriel puis a
  planté a besoin de sa jambe de retour.
- **Des bornes journalisées.** Cap d'itérations et budget de coût dans l'état du workflow survivent
  au crash ; un compteur en mémoire non.

## Ce qu'il n'apporte pas

- **Pas les réessais.** C'est la table stakes, et `symfony/ai-failover-platform` en couvre déjà une
  part. Attention même au piège inverse : Durable qui retente une activité qui, dedans, a déjà
  basculé sur trois fournisseurs, ce sont 3×N appels payants.
- **Pas la fiabilité.** L'exécution durable rend un agent faux **fiablement faux**, et rend une
  boucle infinie **infiniment durable**. Résilience aux pannes et fiabilité sont deux choses ; la
  seconde demande des évaluations, des garde-fous de sortie et des bornes, dont rien n'est du
  ressort de Durable.
- **Pas le streaming.** Une activité rend une valeur une fois. Journaliser le résultat assemblé,
  streamer sur un canal latéral.

## Ce que le rejeu a mesuré

Le test unitaire tourne sur le runner en mémoire en mode distribué : chaque `await` suspend le fiber
et **rejoue le code du workflow depuis le début**. Aucune simulation de crash n'est nécessaire — le
rejeu est le régime normal.

Sur un scénario à 3 appels modèle et 2 appels d'outil : **6 réexécutions** du code de workflow, et
pourtant l'activité d'appel modèle s'exécute **exactement 3 fois**, celle d'appel d'outil
**exactement 2 fois**. Le journal court-circuite le rejeu ; rien n'est repayé.

Et les charges sortantes sont **identiques entre deux exécutions indépendantes** — vérifié par
mutation : un `uniqid()` glissé dans le prompt fait rougir l'assertion. C'est ce qui rend le rejeu
sûr, et c'est fragile : le prompt système et la liste d'outils doivent être **journalisés**, pas
relus depuis la configuration au rejeu. Ajouter un outil change sinon le prompt rejoué.

## Ce qui n'est pas prouvé

- **Aucun vrai fournisseur.** Le convertisseur de réponses est écrit à la main sur la forme « chat
  completions ». Brancher un `symfony/ai-*-platform` le remplacerait sans toucher au reste — mais ce
  n'est pas fait.
- **Aucun crash inter-processus.** Le test tourne en mémoire. Le runner y rejoue pour de vrai, mais
  un vrai redémarrage de processus reste à démontrer.
- **Les blocs de raisonnement passent, mais la signature reste dehors.** Le convertisseur ne lisait
  d'abord que `content` et `tool_calls` : un `reasoning_content` journalisé était perdu — sans
  exception et sans trace, le tour d'après partait simplement amputé. Il lit maintenant le champ et
  rend un `MultiPartResult` ; `Message::toContent()` le déplie en `Thinking`, et
  `AssistantMessageNormalizer` le remet sur le fil au tour suivant. Deux tests le tiennent, vérifiés
  par mutation.

  Ce qui reste dehors, c'est la **signature** — le champ dont le docblock de `Thinking` dit qu'il
  sert « to verify thinking blocks when they are replayed on a subsequent turn ». Aucun normaliseur
  de `ai-platform` ne l'écrit : `AssistantMessageNormalizer` concatène le contenu dans
  `reasoning_content` et ne lit jamais `getSignature()`. Le rejeu signé suppose donc un bridge
  fournisseur qui remplace ce normaliseur — plausible, c'est à ça que sert le `Contract`, mais
  invérifiable ici : aucun `ai-*-platform` n'est installé.

  **La leçon vaut au-delà du raisonnement.** Ce correctif n'en était pas un : c'est un **point de
  changement**. Tant que le convertisseur laissait tomber le champ, il le laissait tomber *de la
  même façon à chaque rejeu* — déterministe, donc sûr. Le jour où on le corrige, le journal contient
  toujours le même JSON mais le convertisseur en extrait davantage : le message reconstruit porte un
  champ de plus, et la charge du tour N+1 ne ressemble plus à celle qui avait été envoyée. Toutes les
  exécutions en vol divergent. Ici c'est sans conséquence — un prototype, rien en vol. En production
  ce genre de correction se déclare et se garde ; elle ne se glisse pas dans un patch.

  La contrepartie est bonne, et elle vient d'un choix : journaliser la réponse **brute** plutôt qu'un
  DTO converti. Le raisonnement était déjà dans le journal de toutes les exécutions passées, avant
  même que quelque chose le lise. Le journal transporte des champs que le convertisseur ne connaît
  pas encore — c'est ce qui a rendu la correction gratuite.
- **La classification d'échec.** Une activité d'outil qui échoue tue aujourd'hui l'appel d'agent.
  C'est un défaut, pas une décision.
- **Le socle bouge.** `symfony/ai` est en 0.x, treize versions mineures à ce jour, sans promesse de
  compatibilité. Les quatre coutures utilisées sont des interfaces publiques, mais rien ne garantit
  leur forme à la mineure suivante. C'est la raison pour laquelle ceci est un motif et non un paquet.
