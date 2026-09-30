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
| `waiting on` | Liste | Liste et page de l'exécution | Liste et page de l'exécution | Section de l'exécution |
| Une ligne par action | Oui | Oui, plus une table du journal | Oui | Non, une ligne par événement |
| Temps de file hachuré | Oui | Oui | Sur la frise | Non |
| Rouge sur l'événement en échec | Oui | Oui | Sur la frise | Non |
| Table des opérations Nexus | Oui | Oui | Oui | Oui |
| Présence des workers | Oui | Oui, journal et activity | Non | Non |
| Masquage des charges utiles | Replié | Replié | Replié | Toujours ouvert |
| Langues | Anglais, français | Anglais | Anglais, français | Anglais |

## Les mots à l'écran

Sylius et Filament affichent leurs libellés en anglais ou en français ; la traduction française
suit le libellé anglais dans chaque case.

| | Sylius | Magento | Filament | Profileur web |
| --- | --- | --- | --- | --- |
| Intitulé de l'issue | `Outcome` (`Issue`) | `Status` | `Outcome` (`Issue`) | `Status` |
| Intitulé de l'historique enregistré | `Run details` (`Détail de l'exécution`) | `Timeline`, `Journal` | `History` (`Historique`) | `Event history` |
| Intitulé de l'identifiant | `Execution` (`Exécution`) | `Execution`, `Run` | `Execution` (`Exécution`) | `Workflow ID` |
| Valeur de l'issue | Traduite, en capitales | Valeur de l'énumération sur la page de l'exécution, première lettre en capitale dans la grille | Traduite | Vocabulaire propre : `Finished`, `Queued (no journal yet)`, `Pending`, `Cancellation requested`, `Continue as new` |

## Les comportements à connaître

- **Compteurs Magento.** Ils couvrent toute la fenêtre de 200 exécutions et ignorent les filtres de
  la grille.
- **Issue sous Filament.** La liste lit toutes les issues.
- **Page d'une exécution sous Sylius.** Elle n'affiche pas la ligne `waiting on`, que la liste
  affiche.
- **Cases vides.** La grille Sylius laisse la date de début vide quand une exécution n'en a pas, et
  la colonne Notes de Filament laisse vide une note vide. Magento et le profileur affichent un
  tiret.
- **Poursuivie sous un nouveau nom.** Gris sous Sylius et Filament, violet dans le profileur, sans
  couleur sous Magento.
- **Profileur.** Seules apparaissent les exécutions envoyées pendant la requête, plus celles que
  nomme le paramètre de requête `durable_execution`. Voir [la page du profileur](../profiler/).

## Voir aussi

- [Sylius](../sylius/), [Magento](../magento/), [Filament](../filament/) et le
  [profileur web](../profiler/), une page chacun
