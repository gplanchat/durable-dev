---
title: Le tableau de bord dans Filament
weight: 25
---

# Le tableau de bord dans Filament

Pour suivre les workflows d'une application Laravel depuis un panneau Filament, installez
`gplanchat/durable-filament` ([Paquets](../../packages/)) et enregistrez le plugin sur le panneau :

```php
use Gplanchat\Durable\Filament\DurableFilamentPlugin;

$panel->plugin(DurableFilamentPlugin::make());
```

Le panneau affiche alors **Exécutions Durable** dans sa navigation. Le plugin fonctionne sur
Filament 3 et 4, lit le catalogue que `gplanchat/durable-laravel` lie dans le conteneur (en mémoire,
Illuminate ou Temporal) et est en lecture seule.

## Ce que la page propose

- **L'état du backend**, daté.
- **Des compteurs** par issue, avec un nombre **En attente d'un worker**, sur les exécutions de la
  page.
- **Des filtres** sur le nom du workflow et sur le début de l'identifiant d'exécution, là où le
  backend sait les appliquer.
- **La liste des exécutions**, 20 par page, en avant par curseur. Chaque ligne porte l'identifiant d'exécution (un lien vers l'exécution), l'issue, le workflow, la date de démarrage et une note (`waiting for a worker`, `waiting on …`).
- **Une page d'exécution**, ouverte depuis une ligne : l'issue, ce qu'elle attend, ses [opérations
  Nexus](../../nexus/) quand le catalogue sait les lister, et son historique, un bloc par action, sous une frise.
  Voir [Lire une exécution](../reading-a-run/).

## Ce qu'elle ne montre pas

La page n'a ni filtre sur l'issue, ni panneau de présence des workers. Voir [Parité](../parity/).

## Langue et contenus enregistrés

Anglais et français. Un contenu enregistré se déplie à la demande et est masqué par ce à quoi votre
application lie `Gplanchat\Durable\Observation\PayloadRedactorInterface` dans le conteneur ; sinon,
le masquage par nom de clé s'applique.
