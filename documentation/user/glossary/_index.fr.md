---
title: Glossaire
weight: 50
---

# Glossaire

Les mots que ce guide emploie dans un sens précis. Chaque entrée dit ce que le mot veut dire ici, et
où le mécanisme qui le porte est décrit.

**Exécution** — un déroulement durable d'un workflow, identifié par un identifiant d'exécution, de
son démarrage à sa complétion, son échec ou son annulation. Elle survit à la mort du processus qui
la faisait tourner : un autre worker la reprend depuis son journal.

**Workflow** — la classe PHP qui décrit les étapes d'une exécution, marquée `#[AsWorkflow]`, avec
une seule `#[AsWorkflowMethod]`. Son code doit être déterministe, parce qu'il est rejoué : il
demande à l'environnement l'heure, l'aléatoire et les entrées-sorties au lieu de les prendre
lui-même. Voir [Écrire un workflow](../workflows/).

**Activité** — une unité d'effet de bord (un appel HTTP, une écriture en base, un courriel) déclarée
sur une interface de contrat avec `#[AsActivity]` et implémentée par une classe portant
`#[AsActivityHandler]`. Une activité s'exécute une fois, sur un worker, et son résultat est écrit
au journal ; un rejeu lit le résultat au lieu de la relancer. Voir [Écrire des activités](../activities/).

**Journal** (ou *magasin d'événements*) — la suite d'événements, en ajout seul, qui enregistre tout ce
qu'une exécution a décidé et reçu : activités planifiées et terminées, minuteurs, signaux, effets de
bord, sa fin. C'est ce qu'une reprise lit. Trois stockages le portent : une base SQL (Doctrine DBAL
ou Illuminate), l'historique propre de Temporal, ou la mémoire pour les tests. Voir
[Backends](../backends/).

**Rejeu** — la façon dont une exécution reprend : le code du workflow tourne à nouveau depuis sa
première ligne, et chaque `await` est servi par le journal jusqu'à la première étape sans résultat
enregistré, là où le vrai travail recommence. Le rejeu est la raison pour laquelle le code doit être
déterministe. Voir [Concepts](../concepts/).

**Slot** — la position d'un appel dans le code du workflow : la troisième activité, le premier
minuteur, le deuxième effet de bord. Le rejeu apparie un appel à son résultat enregistré par slot ;
depuis DUR042 la garde compare aussi le nom et la charge de l'appel, et fait échouer l'exécution
plutôt que servir le résultat d'un appel à un autre (la **garde de divergence**). Voir
[Faire évoluer un workflow en cours](../deploying/).

**Curseur** — la position depuis laquelle le journal est lu. Le rejeu parcourt le journal avec un
curseur, un événement à la fois ; le catalogue des runs pagine aussi ses listes avec un curseur
opaque, pour qu'un lien vers « la page suivante » reste valable pendant que des runs arrivent.

**Effet de bord** — une petite valeur non déterministe dont le workflow a besoin (un identifiant, un
nombre aléatoire, l'heure qu'il est), enregistrée une fois par `sideEffect()` et servie depuis le
journal au rejeu. Pour tout ce qui fait des entrées-sorties, une activité.

**Minuteur** — une attente durable : `sleep()` ou une échéance sur `await()`. Il est journalisé à sa
pose et à son déclenchement, pour qu'une reprise sache si l'attente est finie sans qu'un processus
soit resté en vie pour la compter.

**Signal, requête, mise à jour** — les trois entrées depuis l'extérieur d'une exécution. Un *signal*
pousse un fait dedans et est journalisé ; une *requête* lit l'état sans le changer ; une *mise à
jour* pousse un fait et attend la réponse du workflow. Voir [Écrire un workflow](../workflows/).

**Backend** — où vit le journal et qui planifie le travail : **en mémoire** (tests), **DBAL** ou
**Illuminate** (une base SQL, la file propre de l'application), ou **Temporal** (un cluster ; le
pont parle gRPC et n'utilise pas le SDK PHP officiel). Le code du workflow ne change pas de l'un à
l'autre. Voir [Backends](../backends/).

**Worker** — le processus qui tire le travail : il rejoue les workflows, exécute les activités et
sert les opérations Nexus. Sur Symfony c'est `messenger:consume` sur les transports durables ; sur
Laravel le worker de file de l'application, ou sur Temporal `durable:temporal-worker` et son
`--role=activity` ; sur Magento
`bin/magento durable:worker`. Rien n'avance sans lui. Voir [Premiers pas](../getting-started/).

**Workflow enfant** — une exécution démarrée par une autre, qui attend son résultat comme elle
attend une activité. **Continue-as-new** referme le journal d'une exécution et en ouvre un neuf pour
le même travail, avec l'état qu'elle choisit d'emporter, pour que le journal d'un workflow qui vit
longtemps ne grossisse pas sans fin.

**Opération Nexus** — une opération servie par un autre service, avec son propre contrat, qu'un
workflow appelle comme il appelle une activité. Temporal seulement : les backends à journal la
refusent par construction. Voir [Opérations Nexus](../nexus/).

**Point de changement** — une bifurcation nommée dans le code du workflow (`version()`) qui permet à
un nouveau déploiement de se comporter autrement pour les exécutions démarrées après lui, pendant
que les exécutions en vol gardent l'ancienne branche. Voir
[Faire évoluer un workflow en cours](../deploying/).
