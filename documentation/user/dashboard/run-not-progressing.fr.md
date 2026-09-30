---
title: Une exécution n'avance pas
weight: 60
---

# Une exécution n'avance pas

Vous avez ouvert le tableau de bord et une exécution reste où elle était, ou la liste n'affiche
rien. Chaque entrée ci-dessous part de ce que dit la page. Aucun de ces états ne perd une étape déjà
enregistrée : le [journal](../../glossary/) (les étapes enregistrées d'une exécution) garde chaque étape terminée, et l'exécution reprend à la première qui
manque.

## La ligne affiche `waiting for a worker · 42 s`

L'exécution a été envoyée et rien ne l'a consommée. La file n'a pas de worker, ou son worker est arrêté. Démarrez-en un :

- Symfony : [`durable:worker`](../../getting-started/#5-faire-tourner-un-consommateur-sinon-rien-narrive)
- Laravel : `php artisan queue:work`

La ligne disparaît quand un worker prend l'exécution en charge. Sylius et Filament comptent aussi
ces exécutions sous **En attente d'un worker**.

## L'exécution est en cours et ne porte pas cette ligne

Un worker l'a prise en charge. Lisez la ligne `waiting on` : un minuteur, une tentative d'activité ou
une condition explique l'attente, et une condition nomme l'endroit où elle est écrite, ou le libellé
donné à `await()`. Si l'attente est une opération Nexus, le tableau des opérations indique si elle
est en cours.

## La liste est vide

Le message au-dessus de la liste dit dans lequel des trois cas vous êtes.

| La page dit | Cause | Action |
| --- | --- | --- |
| Aucun backend lisible n'est configuré | Aucun backend ne sert le tableau de bord | En configurer un, voir [Backends](../../backends/) |
| Le backend est injoignable | Le backend nommé est arrêté | Le redémarrer |
| Le journal meurt avec la requête | Le backend en mémoire sous PHP-FPM : la requête qui rend la page n'a exécuté aucun workflow | Configurer un backend partagé entre les processus |

Magento n'affiche pas de bandeau pour le premier cas : il lit le backend en mémoire quand rien
d'autre n'est configuré.

## Aucun worker n'interroge Temporal

Rien n'échoue : une exécution s'arrête à sa première tâche de ce type. Sur Sylius et Magento, le
tableau de bord nomme chaque rôle dont la file n'est interrogée par aucun worker. Depuis un shell,
`bin/console durable:health` sort avec un code non nul quand la file d'un rôle n'a été interrogée
par personne depuis deux minutes, et nomme le `durable:worker --role` à démarrer.

## `waiting for a worker` n'apparaît jamais

Le backend n'a pas cette information. Les backends SQL l'ont, sur une table des exécutions qui a la
colonne `picked_up_at` (voir [la procédure de mise à jour](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md)
pour une table créée avant elle). Temporal ne l'a pas depuis la liste des exécutions, et la grille
Magento ne l'affiche pas : Temporal UI liste les tâches en attente.

## Les filtres par nom et par identifiant manquent

Les deux filtres n'apparaissent que là où le backend sait les appliquer. Sur Temporal, [activez les
attributs de recherche](../../backends/#register-durables-search-attributes).

## Voir aussi

- [Lire une exécution](../reading-a-run/)
- [Parité](../parity/)
