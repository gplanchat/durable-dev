---
title: Un agent IA interruptible
weight: 20
---

# Un agent IA interruptible

> [!WARNING]
> **Prototype.** Le code décrit ici vit sur la branche `spike/agent-durable-symfony-ai`, qui n'est
> pas fusionnée. Il tourne — voir [Comment on la lance](#comment-on-la-lance) — mais cette page
> publie **le motif**, pas un paquet : les quatre décisions ci-dessous s'appliquent à n'importe quel
> agent Symfony AI, avec ou sans le code du dépôt.

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

## Ce que la démo montre

Le modèle scripté répond à des mots déclencheurs en français — c'est une doublure, pas un modèle
de langue — donc les phrases ci-dessous sont celles à taper. Un vrai fournisseur décide seul.

- **Une garde sur chaque appel d'outil.** Chaque outil porte un effet : `read`, `write` ou
  `external`. Le mode — `standard`, `edition`, `auto` — dit quels effets passent sans demander ;
  le reste suspend le workflow jusqu'à ton accord ou ton refus, avec une échéance d'un quart
  d'heure. « Quelle est la météo à Paris ? » est une lecture et passe ; « envoie un mail » est
  externe et t'attend.
- **Une question à l'humain**, sous forme de questionnaire sur lequel le workflow attend, comme il
  attend une validation : « demande-moi… », ou « lance un import » pour une question que le modèle
  pose de lui-même parce que la demande est ambiguë. Ajoute « choix multiple » pour la forme à
  plusieurs réponses.
- **Surveiller et alerter.** « surveille la livraison » endort l'agent sur un fait métier.
  `php bin/console app:agent:evenement commande.expediee` produit ce fait depuis la ligne de
  commande, et l'agent se réveille en sachant ce qu'il faisait et pourquoi.
- **Déléguer.** « délègue… » confie une mission à un sous-agent, qui hérite du mode courant et
  n'obtient jamais plus d'autorité que son parent.
- **Le raisonnement dans le fil.** Le raisonnement du modèle traverse la frontière avec la réponse
  et s'affiche replié dessous.
- **Un budget de contexte** sur l'appel modèle. Quand la conversation le dépasse, le run la
  compacte en résumé et continue ; `?contexte=N` sur l'URL du chat réduit le budget pour le voir
  faire. Au bout de quarante tours le run passe la main à un run neuf, pour le coût, pas pour la
  taille.
- **Clore et reprendre.** Une conversation close — ou muette pendant une heure — termine son
  exécution. Reprendre en ouvre une neuve, qui repart d'un résumé de l'ancienne, lui-même un appel
  modèle journalisé.
- **Un canal poussé, facultatif.** Mercure pousse « cette exécution a bougé » ; la page sonde
  aussi, donc sans concentrateur elle marche encore, seulement plus lentement.
- Tout cela se lit dans l'UI de Temporal comme les événements d'un seul workflow `Ai_DurableAgent`.

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

## Comment on la lance

Tout est sous `symfony/` sur la branche. Prérequis : PHP 8.2 avec `ext-grpc`, Composer, Docker.
Aucune clé d'API : le modèle scripté est le défaut.

```bash
git switch spike/agent-durable-symfony-ai
cd symfony
composer install
docker compose up -d --wait   # Postgres, Temporal sur 7234, son UI sur 8089, Mercure sur 33000
```

Puis trois terminaux, depuis le même dossier — les deux workers Temporal sont la démo, et ce sont
des processus séparés parce que leurs long-polls gRPC affament tout transport qui partage leur
boucle :

```bash
php bin/console messenger:consume durable_temporal_journal
php bin/console messenger:consume durable_temporal_activity
php -S localhost:8012 -t public
```

Ouvrir <http://localhost:8012/durable/chat>. Les samples sont sur <http://localhost:8012/>, l'UI de
Temporal sur <http://localhost:8089/> — chaque conversation y est un workflow `Ai_DurableAgent`. Le
port 8012 est une suggestion ; n'importe quel port libre convient. Si 7234 ou 8089 sont pris,
poser `TEMPORAL_FRONTEND_PORT` et `TEMPORAL_UI_PORT` avant `docker compose up`, et remplacer 7234
dans les DSN `temporal://` de `.env.dev` et de `config/packages/messenger.yaml`.

Deux choses qu'on ne devinerait pas :

- **Un worker qui perd Temporal s'arrête.** Il ne retente pas la connexion ; `docker compose up`
  d'abord, les workers ensuite, et un worker arrêté se relance à la main. Sans les workers, un
  message est accepté et rien ne bouge.
- **Pour parler à Mistral plutôt qu'au script**, poser `MISTRAL_API_KEY` dans `.env.local` et
  lancer `php bin/console cache:clear`. Rien d'autre ne change.

Avec la CLI Symfony, `symfony serve --port=8012 -d` remplace les trois terminaux : elle démarre les
workers de `.symfony.local.yaml`, et `docker compose up` comme l'un d'eux — ce qui veut dire que la
stack Docker vit et meurt avec le serveur. `symfony server:stop` arrête aussi Temporal, et les
workers avec. Les hôtes `samples.durable.localhost` et `agent.durable.localhost` ne s'activent que
si on le demande : les `APP_HOST_*` de `.env` sont vides par défaut, et c'est ce qui fait marcher
`localhost` partout.

`docker compose down` arrête la stack. Le journal est dans son volume Postgres ; `down -v` l'oublie.

## Ce qui n'est pas prouvé

- **Un vrai fournisseur, peu exercé.** Le convertisseur écrit à la main a disparu : la branche
  utilise les normaliseurs et le convertisseur de `symfony/ai-mistral-platform`, et ne remplace que
  son client HTTP. Le lancement par défaut ci-dessus ne l'appelle pourtant jamais — c'est le client
  scripté qui répond — et rien dans la suite de tests ne l'appelle non plus. Les longues
  conversations, les débordements et les reprises ont tous été joués contre le client scripté, pas
  contre Mistral.
- **Aucun crash inter-processus scripté.** Le test tourne en mémoire ; le runner y rejoue pour de
  vrai. La démo, elle, tourne bien sur plusieurs processus — le serveur web signale, un worker
  appelle le modèle, un autre fait tourner le workflow — mais en tuer un au milieu d'une
  conversation et le voir reprendre, c'est un geste à la main, pas un test de la suite.
- **Les blocs de raisonnement passent, mais la signature reste dehors.** Le premier jet
  journalisait le raisonnement dans un champ `reasoning_content` à côté du message, et le pont
  Mistral refuse cette forme par un 422. Le raisonnement voyage maintenant en morceaux `thinking`
  dans `content`, qui est la forme de Mistral, et la lecture accepte les deux formes.

  Ce qui reste dehors, c'est la **signature** — le champ dont le docblock de `Thinking` dit qu'il
  sert « to verify thinking blocks when they are replayed on a subsequent turn ». Aucun normaliseur
  de `ai-platform` ne l'écrit : `AssistantMessageNormalizer` ne lit jamais `getSignature()`. Le
  rejeu signé suppose donc un bridge fournisseur qui remplace ce normaliseur — plausible, c'est à
  ça que sert le `Contract`, mais pas vérifié ici.

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
