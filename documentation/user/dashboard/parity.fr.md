---
title: Parité entre les surfaces
weight: 40
---

# Parité entre les surfaces

Ce que montre chaque surface, vérifié sur les sources de `main` le 2026-09-30. La [vue
d'ensemble](../) dit pourquoi le tableau de bord est unique ; cette page liste où les quatre
surfaces diffèrent encore. Elle change quand un écart se referme.

## Ce que montre chaque surface

| | Sylius | Magento | Filament | Profileur web |
| --- | --- | --- | --- | --- |
| Exécutions listées | Toutes | Les 200 plus récentes | Toutes | Celles vues pendant une requête, plus 20 nommées dans `durable_execution` au plus |
| État du backend | 4 états, dont 3 datés | 3 états, celui du backend en mémoire non daté | 4 états, dont 3 datés | Aucun |
| Compteurs | Par issue, sur la page | Par issue, sur la fenêtre | Par issue, sur la page | Aucun |
| Filtre par issue | Oui | Oui | Non | Non |
| Filtre par nom de workflow | Nom entier, là où le backend sait l'appliquer | Contient, sans tenir compte de la casse, dans la fenêtre | Nom entier, là où le backend sait l'appliquer | Non |
| Filtre par identifiant d'exécution | Préfixe, là où le backend sait l'appliquer | Contient, dans la fenêtre | Préfixe, là où le backend sait l'appliquer | Non |
| `waiting for a worker` | Ligne et compteur | Non | Ligne et compteur | Non |
| `waiting on` | Liste et page de l'exécution | Liste et page de l'exécution | Liste et page de l'exécution | Section de l'exécution |
| Une ligne par action | Oui | Oui, plus une table du journal | Oui | Non, une ligne par événement |
| Temps de file hachuré | Oui | Oui | Sur la frise | Non |
| Rouge sur l'événement en échec | Oui | Oui | Sur la frise | Non |
| Table des opérations Nexus | Oui | Oui | Oui | Oui |
| Présence des workers | Oui | Oui, journal et activity | Non | Non |
| Masquage des charges utiles | Replié | Replié | Replié | Toujours ouvert |
| Langues | Anglais, français | Anglais | Anglais, français | Anglais |

## Les mots à l'écran

Les quatre surfaces emploient les mêmes mots. Sylius et Filament les affichent en anglais ou en
français, Magento et le profileur web en anglais.

| | Mot commun | Français, sous Sylius et Filament |
| --- | --- | --- |
| Intitulé de l'issue | `Outcome` | `Issue` |
| Intitulé de l'historique enregistré | `History` | `Historique` |
| Intitulé de l'identifiant | `Execution` | `Exécution` |
| Intitulé de l'identifiant propre au backend | `Backend run`, sur les trois tableaux de bord | `Exécution côté backend` |
| Valeur de l'issue | `Running`, `Completed`, `Failed`, `Cancelled`, `Continued as new` | `En cours`, `Terminée`, `En échec`, `Annulée`, `Poursuivie à neuf` |

Trois éléments restent propres à une surface :

- La page d'une exécution sous Magento garde un tableau **Journal** sous `History`, une ligne par
  événement.
- Le profileur ajoute trois états qui ne sont pas des issues, puisqu'ils disent où en est une
  exécution sur la requête : `Queued (no journal yet)`, `Pending` et `Cancellation requested`.
- La carte de l'exécution sous Sylius s'intitule `Run details` (`Détail de l'exécution`), et son
  intitulé `History` (`Historique`) se trouve au-dessus de la frise.
- Le profileur n'affiche aucun identifiant de run côté backend.

## Les comportements à connaître

- **Compteurs Magento.** Ils couvrent toute la fenêtre de 200 exécutions et ignorent les filtres de
  la grille.
- **Issue sous Filament.** La liste lit toutes les issues.

- **Poursuivie à neuf.** Gris sous Sylius et Filament, violet dans le profileur, sans
  couleur sous Magento.
- **Profileur.** Seules apparaissent les exécutions envoyées pendant la requête, plus celles que
  nomme le paramètre de requête `durable_execution`. Voir [la page du profileur](../profiler/).

## Voir aussi

- [Sylius](../sylius/), [Magento](../magento/), [Filament](../filament/) et le
  [profileur web](../profiler/), une page chacun
