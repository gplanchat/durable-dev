---
title: Le tableau de bord
weight: 17
---

# Le tableau de bord

Il n'y en a **qu'un**. Sylius et Magento le rendent dans leur propre habillage d'administration, et
deux autres surfaces arrivent, mais ce qu'ils montrent, la façon dont une exécution est regroupée et
les mots employés se décident une fois — dans `gplanchat/durable`, à côté du modèle d'observation que
les pages lisent.

Ce n'est pas du rangement. Un panneau qu'une surface a et qu'une autre n'a pas est une question à
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
| Aucun backend lisible n'est configuré | Le dit **sans nommer** de backend en particulier — il se peut qu'aucun n'ait jamais été de la partie | En configurer un |
| Un backend répond | Le nomme, et dit quand la vérification a eu lieu | Rien |
| Un backend est injoignable | Le nomme, pour que vous sachiez quoi rallumer, et date la vérification | Le rallumer |
| Un backend répond, et son journal meurt avec la requête | Dit qu'une liste vide est ici la bonne réponse et non une panne, et quoi configurer pour lire à travers les processus | Rien, ou configurer un backend partagé |

Le dernier cas est celui du journal en mémoire sous PHP-FPM : la requête qui rend le tableau de bord
n'a exécuté aucun workflow, elle ne voit donc rien, et elle a raison. Le masquer vous apprendrait que
rien n'a tourné du tout.

### 2. Les exécutions

Filtrables par issue — en cours, terminée, échouée, annulée, poursuivie sous un nouveau nom — et
paginées.

Une exécution **poursuivie sous un nouveau nom** n'est pas un échec. C'est une fin normale : le
composant la traite comme une exécution neuve, et celle qui passe la main s'est terminée sans erreur.
Les confondre ferait apparaître en rouge des workflows longs parfaitement sains.

### 3. Les compteurs, sur ce que vous regardez

Un par issue, et ils couvrent **l'ensemble que la liste parcourt** — jamais tout l'historique de
l'application. Chaque surface dit lequel, parce que cela dépend de la façon dont l'hôte pagine :

- le tableau de bord Sylius demande une page et la compte ;
- la grille Magento pagine par décalage dans une fenêtre bornée, l'ensemble est donc la fenêtre, et
  l'écran le dit dès qu'elle est pleine.

Un intitulé « Total » sous lequel on lit vingt vous apprendrait qu'une application ayant enregistré
cinq cents exécutions en a vingt.

### 4. L'historique d'une exécution — une ligne par **action**

Une action n'est pas un événement. Une activité planifiée, démarrée puis terminée est **une action et
trois événements** ; un minuteur aussi, une opération Nexus aussi. Une frise rangée par nature — « les
activités », « les signaux » — vous oblige à recoller trois lignes de l'œil pour répondre à la
question avec laquelle vous êtes venu : combien de temps *celle-là* a-t-elle duré.

Chaque ligne est donc une action, placée dans le temps, et sa barre est sa durée :

- **L'exécution elle-même est la première ligne**, nommée d'après le workflow et portant ses tâches.
  Un workflow enfant garde sa propre ligne ; un signal reçu et une mise à jour traitée aussi.
- **La barre est découpée entre événements consécutifs.** Sans cela, dès que l'exécution occupe une
  ligne, sa barre couvre tout le run et dit « le run a duré le temps du run » — et les vingt-deux
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
  du workflow enfant ou de l'opération — les suivants ne portent qu'un numéro. Un journal affichant le
  libellé propre de chaque événement masquerait, sur deux lignes sur trois, le nom que vous cherchez.
  Un minuteur n'a aucun nom métier : c'est son délai qui le nomme.

Chaque événement se déplie sur **ce que le backend a enregistré avec lui** : les arguments d'appel
d'une activité, ce qu'elle a rendu, la classe et le message d'un échec. Ce contenu est le vocabulaire
du backend et n'est délibérément **pas** normalisé — décider lesquels de ses faits méritent un nom
commun n'a de sens qu'une fois qu'on aura vu ce que les exploitants y cherchent, et serait une
fabrication avant. Un événement avec lequel le backend n'a rien enregistré garde une ligne simple
plutôt qu'un dépliant qui s'ouvre sur du vide.

## Un fait qu'un backend n'a pas est montré comme absent

Deux absences se ressemblent et n'en sont pas une seule :

- **Le backend n'a pas cette notion** — une file de tâches sur un backend qui n'en a aucune, un
  regroupement à travers les continuations sur un backend qui n'en enregistre pas. Rien n'est montré,
  et aucune colonne n'est proposée non plus : une colonne vide vous apprendrait que *cette exécution*
  n'a pas de file, alors que c'est le backend qui n'a pas de files.
- **Cette exécution n'a pas ce fait** — une exécution en cours n'a pas de date de fin. La colonne
  existe pour ses voisines, elle se lit donc, dans un tableau, comme un tiret cadratin explicite. Une
  case vide se lit comme un rendu qui a échoué.

## Ce qui diffère d'un hôte à l'autre

L'habillage, et rien que l'habillage.

| | Sylius | Magento |
| --- | --- | --- |
| Où | Menu d'administration → Durable | **System > Durable processes > Process history** |
| La liste | Cartes Tabler, pagination par curseur | La grille standard — pagination, signets, contrôle des colonnes, export, et un filtre d'état dont les options viennent de l'énumération |
| Pagination | Curseur, 20 par page | Décalage dans une fenêtre de 200 exécutions, dont l'écran annonce le plafond |
| Lecture seule | Oui | Oui |

Les deux sont en **lecture seule**, et le resteront : ce qu'on vient chercher sur un tableau de bord,
c'est de savoir si une commande est passée, pas de la relancer à la main. Reprendre une exécution
depuis un navigateur contournerait le verrou par exécution.

Mettre des secondes à l'échelle d'une barre est la seule décision de présentation qui appartienne à
l'hôte — il lui faut connaître la largeur de sa colonne, et une surface qui ne rend aucun balisage
n'en a pas. Tout le reste est partagé.

## Voir aussi

- [Paquets](../packages/) — `gplanchat/durable-plugin` pour l'habillage Sylius,
  `gplanchat/durable-magento` pour celui de Magento
- [Backends](../backends/) — lequel enregistre quoi
