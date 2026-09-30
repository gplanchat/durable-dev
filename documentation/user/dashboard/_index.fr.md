---
title: Le tableau de bord
weight: 17
---

# Le tableau de bord

Il n'y en a **qu'un**. Sylius, Magento et Filament le rendent dans leur propre habillage
d'administration, et une surface API Platform arrive. Ce qu'ils montrent et la façon dont une
exécution est regroupée se décident une fois, dans `gplanchat/durable`, à côté du modèle
d'observation que les pages lisent. Le panneau du profileur web de Symfony lit les mêmes journaux
mais répond à une question plus étroite, ce qui s'est passé pendant une requête, et garde donc sa
propre mise en page. [Parité](parity/) liste, ligne par ligne, ce que chaque surface montre
aujourd'hui et où elles diffèrent.

Il ne s'agit pas d'uniformité pour elle-même. Un panneau qu'une surface a et qu'une autre n'a pas est une question à
laquelle une application sait répondre et l'autre non, sur la même exécution, enregistrée par le même
backend. Un exploitant qui travaille sur deux applications de la même maison ne devrait rien avoir à
traduire.

## Ce que tout tableau de bord montre

### 1. L'état du backend

Une liste vide ne dit rien toute seule : elle se lit pareil quand rien n'a tourné, quand la grappe
est tombée, et quand le journal ne survit pas à la requête qui rend la page. La page dit donc lequel
des trois c'est, avant de montrer quoi que ce soit.

| État | Ce que la page dit | Quoi faire |
| --- | --- | --- |
| Aucun backend lisible n'est configuré | Le dit **sans nommer** de backend en particulier, il se peut qu'aucun n'ait jamais été de la partie | En configurer un |
| Un backend répond | Le nomme, et dit quand la vérification a eu lieu | Rien |
| Un backend est injoignable | Le nomme, pour que vous sachiez quoi rallumer, et date la vérification | Le rallumer |
| Un backend répond, et son journal meurt avec la requête | Dit qu'une liste vide est ici la bonne réponse et non une panne, et quoi configurer pour lire à travers les processus | Rien, ou configurer un backend partagé |

Le dernier cas est celui du journal en mémoire sous PHP-FPM : la requête qui rend le tableau de bord
n'a exécuté aucun workflow, elle ne voit donc rien, et elle a raison. Le masquer vous apprendrait que
rien n'a tourné du tout.

Un cluster qui répond peut malgré tout n'avoir **aucun worker** sur la file d'un rôle. Rien n'échoue
alors : une exécution s'arrête à sa première tâche de ce type. Sur Temporal,
`bin/console durable:health` sort en erreur quand la file d'un rôle n'a été interrogée par personne
depuis deux minutes, et nomme le `durable:worker --role` à démarrer : c'est ce code de sortie qu'il
faut surveiller. La commande vérifie workflow et activity quand Temporal tient le journal, et nexus
dès que l'application sert un gestionnaire Nexus. Sur Temporal, le tableau de bord Sylius
affiche le même état au-dessus de la liste des exécutions, une ligne par rôle. La page Magento
aussi, pour les rôles journal et activity.

### 2. Les exécutions

Sylius et Magento filtrent par issue (en cours, terminée, échouée, annulée, poursuivie à neuf) ; Filament et le profileur non. Toutes les listes sauf celle du profileur sont
paginées.

Sous Sylius et Filament, la liste se filtre aussi par nom de workflow (le nom entier) et par le début de
l'identifiant d'exécution. Les deux respectent la casse et prennent `%` et `_` à la lettre. Ils
n'apparaissent que là où le backend sait les appliquer : sur Temporal, il faut [activer ses
attributs de recherche](../backends/#register-durables-search-attributes) ; sans eux, la page ne
filtre que par issue. La grille Magento propose ses propres filtres texte sur le nom du workflow,
l'identifiant d'exécution et l'identifiant de run : le filtre sur le nom du workflow ignore la casse, les deux filtres sur les identifiants respectent le texte tel que saisi, et chacun le cherche n'importe où dans la valeur, parmi les exécutions de sa fenêtre.

Une exécution **poursuivie à neuf** n'est pas un échec. C'est une fin normale : le
composant la traite comme une exécution neuve, et celle qui passe la main s'est terminée sans erreur.
Les confondre ferait apparaître en rouge des workflows longs parfaitement sains.

Une exécution en cours qu'**aucun worker n'a encore prise en charge** le dit, avec depuis quand :
`waiting for a worker · 42 s`. Elle a été envoyée, et rien ne l'a consommée, ce qui veut dire que la file n'a pas de worker, ou que son worker est arrêté : lancez-en un avec [`durable:worker`](../getting-started/#5-faire-tourner-un-consommateur-sinon-rien-narrive)
sous Symfony, ou `php artisan queue:work` sous Laravel.
Une exécution en cours sans cette mention a été prise en charge : elle travaille, ou elle attend un minuteur ou un signal. Sous Sylius et Filament, les compteurs ajoutent un nombre
**En attente d'un worker** (**Waiting for a worker** en anglais) sur la même page.

Les backends SQL savent le dire (DBAL sous Symfony, Illuminate sous Laravel), sur une table des exécutions qui a la
colonne `picked_up_at`, ainsi que le backend en mémoire dans son propre processus. Sous Laravel,
le [panneau Filament](filament/) est la liste des exécutions. Temporal ne le peut pas
depuis la liste des exécutions : ni la mention ni le nombre n'y apparaissent, et Temporal UI montre les
tâches en attente. La grille Magento non plus, puisque ses backends sont celui en mémoire, vide depuis
l'admin, et Temporal. Voir [la procédure de mise à jour](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md)
pour ajouter la colonne à une table créée avant elle.

Une exécution en cours dit aussi **ce qu'elle attend**, à sa dernière suspension :
`waiting on timer "grace period" due at 2026-09-24T10:00:00+00:00`,
`waiting on activity charge attempt 2 in flight`, ou `waiting on condition at src/…/OrderWorkflow.php:42`.
L'attente d'un signal est une condition : la ligne nomme l'endroit où la condition est écrite, sauf si
le workflow lui a donné un libellé (`await(…, label: 'signal approve')` affiche
`waiting on signal approve`, voir [Workflows](../workflows/#attendre-sur-une-condition)). Les mêmes backends le disent, sur une table des exécutions qui a la colonne `waiting_on`.
Temporal le dit aussi, grille Magento comprise, par un mémo `durableWaitingOn` que le worker met à
jour à chaque suspension. Le mémo omet la tentative, puisqu'aucune tâche de workflow ne s'exécute quand une
tentative d'activité démarre, et le résumé d'un minuteur, qui n'est jamais envoyé au serveur.
Sylius affiche la ligne dans la liste, Filament dans la liste et sur la page de l'exécution. Magento
et le profileur écrivent la raison sans le préfixe `waiting on`.

### 3. Les compteurs, sur ce que vous regardez

Un par issue, et ils couvrent **l'ensemble que la liste parcourt**, jamais tout l'historique de
l'application. Chaque surface dit lequel, parce que cela dépend de la façon dont l'hôte pagine :

- les tableaux de bord Sylius et Filament demandent une page et la comptent, et l'intitulé donne le
  nombre d'exécutions qu'elle contient ;
- la grille Magento pagine par décalage dans une fenêtre bornée de 200 exécutions. Ses compteurs
  couvrent cette fenêtre quels que soient les filtres de la grille, et l'écran le dit dès qu'elle
  est pleine.

Un intitulé « Total » sous lequel on lit vingt vous apprendrait qu'une application ayant enregistré
cinq cents exécutions en a vingt. Les compteurs Magento portent une colonne `Total` : elle compte la
fenêtre, pas l'historique. Le profileur n'a pas de compteurs, puisqu'il ne liste que les exécutions
d'une requête.

### 4. L'historique d'une exécution, une ligne par **action** {#4-lhistorique-dune-exécution--une-ligne-par-action}

Une action n'est pas un événement. Une activité planifiée, démarrée puis terminée est **une action et
trois événements** ; un minuteur aussi, une opération Nexus aussi. Une frise rangée par nature, « les
activités » puis « les signaux », vous oblige à recoller trois lignes de l'œil pour répondre à la
question avec laquelle vous êtes venu : combien de temps *celle-là* a-t-elle duré.

Chaque ligne est donc une action, placée dans le temps, et sa barre est sa durée :

- **L'exécution elle-même est la première ligne**, nommée d'après le workflow et portant ses tâches.
  Un workflow enfant garde sa propre ligne ; un signal reçu et une mise à jour traitée aussi.
- **La barre est découpée entre événements consécutifs.** Sans cela, dès que l'exécution occupe une
  ligne, sa barre couvre tout le run et dit « le run a duré le temps du run », et les vingt-deux
  secondes passées à attendre un worker, le seul fait intéressant, y disparaissent.
- **Un intervalle hachuré est une file, pas du travail.** Le temps passé à attendre qu'on prenne la
  tâche et celui passé à la faire dessinent le même rectangle, et la première question devant une
  exécution lente est de savoir lequel des deux on regarde : son code, ou personne au bout du fil.
- **La position vient de l'heure enregistrée, pas du rang.** C'est ce qui fait qu'une exécution ayant
  passé vingt-deux de ses vingt-quatre secondes à attendre en a l'air. Les événements d'une même
  seconde restent distingués, si bien qu'une exécution plus courte qu'une seconde est une frise et
  non une pile.
- **Le rouge marque l'événement qui a mal tourné, pas l'action.** Une activité qui a échoué deux fois
  et réussi la troisième porte du rouge et se termine bien. Une annulation n'est pas peinte en rouge :
  c'est une issue que quelqu'un a demandée, pas une panne.
- **Chaque ligne nomme son action.** Seul l'événement qui ouvre une action porte le nom de l'activité,
  du workflow enfant ou de l'opération ; les suivants ne portent qu'un numéro. Un journal affichant le
  libellé propre de chaque événement masquerait, sur deux lignes sur trois, le nom que vous cherchez.
  Un minuteur n'a aucun nom métier : c'est son délai qui le nomme.

Chaque événement se déplie sur **ce que le backend a enregistré avec lui** : les arguments d'appel
d'une activité, ce qu'elle a rendu, la classe et le message d'un échec. Ce contenu est le vocabulaire
du backend et n'est délibérément **pas** normalisé, car décider lesquels de ses faits méritent un nom
commun n'a de sens qu'une fois qu'on aura vu ce que les exploitants y cherchent, et serait une
fabrication avant. Un événement avec lequel le backend n'a rien enregistré garde une ligne simple
plutôt qu'un dépliant qui s'ouvre sur du vide.

Ce qui se déplie est masqué comme le masquent `durable:execution:diagnose` et le profileur web : les
valeurs rangées sous des clés comme `password`, `token`, `secret`, `authorization`, `card` ou `api_key`
sont remplacées, et les longues chaînes tronquées. Quiconque a accès à l'administration peut ouvrir une
exécution : cette page n'a donc pas de vue brute. Le masquage se fie au nom de la clé : des données
personnelles rangées sous d'autres clés restent visibles. La page d'une exécution masque avec le même
outil que ces deux-là : sur le plugin Sylius, le service qu'une application déclare comme alias de
`Gplanchat\Durable\Observation\PayloadRedactorInterface` ; sur Magento, une préférence que
l'application déclare pour cette interface ; sur Filament, ce à quoi l'application lie cette
interface dans son conteneur.

## Un fait qu'un backend n'a pas est montré comme absent

Deux absences se ressemblent et n'en sont pas une seule :

- **Le backend n'a pas cette notion.** Une file de tâches sur un backend qui n'en a aucune, un
  regroupement à travers les continuations sur un backend qui n'en enregistre pas. Rien n'est montré,
  et aucune colonne n'est proposée non plus : une colonne vide vous apprendrait que *cette exécution*
  n'a pas de file, alors que c'est le backend qui n'a pas de files.
- **Cette exécution n'a pas ce fait.** Une exécution en cours n'a pas de date de fin. La colonne
  existe pour ses voisines, elle se lit donc, dans un tableau, comme un tiret cadratin explicite. Une
  case vide se lit comme un rendu qui a échoué. Magento et le profileur appliquent cette règle. La
  grille Sylius laisse encore vide la date de début d'une telle exécution, et la colonne Notes de
  Filament laisse vide une note vide.

## Ce qui diffère d'une surface à l'autre {#ce-qui-diffère-dun-hôte-à-lautre}

| | Sylius | Magento | Filament | Profileur web |
| --- | --- | --- | --- | --- |
| Où | **Configuration > Tableau de bord Durable** | **System > Durable processes > Process history** | Navigation du panneau > **Exécutions Durable** | Le panneau **Durable** du profileur, et son élément dans la barre d'outils |
| Ce qu'il liste | Toutes les exécutions que le backend connaît | Les 200 exécutions les plus récentes | Toutes les exécutions que le backend connaît | Les exécutions envoyées pendant une requête |
| Pagination | Curseur, 20 par page | Décalage dans la fenêtre de 200 exécutions, dont l'écran annonce le plafond | Curseur, 20 par page | Aucune, 500 événements par journal au plus |
| Lecture seule | Oui | Oui | Oui | Oui |

La liste Sylius est une grille Sylius, celle de Magento la grille standard de l'administration
(pagination, contrôle des colonnes, filtres), et celle de Filament un tableau fait des composants du
panneau : sur Filament 3, une table ne lit qu'une requête Eloquent, et le curseur d'un catalogue ne
va qu'en avant. Elle se rend pareil sur Filament 3 et 4.

Les quatre sont en **lecture seule** : ce qu'on vient chercher sur un tableau de bord,
c'est de savoir si une commande est passée, pas de la relancer à la main. Reprendre une exécution
depuis un navigateur contournerait le verrou par exécution.

Mettre des secondes à l'échelle d'une barre est une décision de présentation qui appartient à
l'hôte, car il lui faut connaître la largeur de sa colonne, et une surface qui ne rend aucun balisage
n'en a pas. Les autres différences sont des manques plutôt que des choix, et [Parité](parity/) les
liste.

## Voir aussi

- [Parité](parity/) compare les quatre surfaces, ligne par ligne
- [Lire une exécution](reading-a-run/) explique la page d'une exécution, quelle que soit la surface
  qui la montre
- [Une exécution n'avance pas](run-not-progressing/) va de ce que vous voyez à ce qu'il faut faire
- Une page par surface : [Sylius](sylius/), [Magento](magento/), [Filament](filament/),
  [profileur web](profiler/)
- [Paquets](../packages/) couvre `gplanchat/durable-plugin` pour l'habillage Sylius,
  `gplanchat/durable-magento` pour celui de Magento, `gplanchat/durable-filament` pour celui de Filament
- [Backends](../backends/) dit lequel enregistre quoi
