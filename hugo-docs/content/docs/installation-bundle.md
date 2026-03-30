---
title: Installation du bundle Symfony
weight: 20
---

Pour une **première mise en route** guidée (commandes dans l’ordre, premier `durable:sample`), préférez le **[parcours tutoriel]({{< relref "/docs/parcours-premier-workflow/" >}})**. Cette page approfondit la configuration une fois le contexte acquis.

## Paquets et prérequis

- **PHP** 8.2 ou supérieur  
- **Symfony** 6.4+ ou 7.4+ pour le bundle  
- **`symfony/messenger`** : nécessaire si vous utilisez le transport Messenger pour les activités ou la reprise de workflows distribués ; la bibliothèque seule ne l’impose pas (transports mémoire ou DBAL possibles sans Messenger).

## Installation Composer

```bash
composer require gplanchat/durable-bundle
```

Le bundle tire **`gplanchat/durable`**. Activez `Gplanchat\Durable\Bundle\DurableBundle` dans votre `config/bundles.php` (selon la version de Symfony, l’import Flex peut le faire automatiquement).

## Fichier de configuration type

L’application exemple du monorepo expose une configuration complète dans `symfony/config/packages/durable.yaml`. Exemple commenté :

```yaml
durable:
    distributed: true
    dbal_connection: durable   # nom de connexion Doctrine — voir la page DBAL
    event_store:
        type: dbal
        table_name: durable_events
    workflow_metadata:
        type: dbal
        table_name: durable_workflow_metadata
    activity_transport:
        type: messenger
        transport_name: durable_activities
    child_workflow:
        async_messenger: true
        parent_link_store:
            type: dbal
            table_name: durable_child_workflow_parent_link
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Durable\Activity\GreetingActivityInterface
```

Points importants :

- **`distributed: true`** : workflows et activités peuvent tourner dans des processus distincts ; la reprise passe par Messenger (voir ADR009 dans le dépôt).  
- **`event_store` / `workflow_metadata`** : persistance du journal et des métadonnées de run (`dbal` ou `in_memory` pour les tests).  
- **`activity_transport`** : `messenger`, `dbal` ou `in_memory` selon votre topologie.  
- **`child_workflow`** : lien parent↔enfant persisté si vous utilisez des workflows enfants asynchrones.  
- **`activity_contracts.contracts`** : liste des interfaces d’activités pour le cache des métadonnées.

Adaptez les noms de transports à votre `config/packages/messenger.yaml`.

## Enregistrer workflows et activités

Les classes de workflow sont généralement chargées avec le tag `durable.workflow` ; les handlers d’activités implémentent le contrat et portent `#[AsDurableActivity]`. Exemple de services :

```yaml
services:
    App\Durable\Workflow\:
        resource: '../src/Durable/Workflow/'
        tags: [durable.workflow]
    App\Durable\Activity\:
        resource: '../src/Durable/Activity/*Handler.php'
```

## Schéma base de données (DBAL)

Les adaptateurs DBAL exposent une API de création de schéma. Initialisez les tables (journal, métadonnées, lien parent–enfant selon la config) :

```bash
php bin/console durable:schema:init
```

La commande utilise la connexion **`durable.dbal.connection`** (alias vers la connexion Doctrine choisie par `durable.dbal_connection`).

## Workers

Les noms de transports doivent correspondre à votre configuration Messenger. Exemples :

```bash
php bin/console messenger:consume durable_workflows durable_activities -vv
```

En mode distribué avec transport Messenger pour les activités, celles-ci sont traitées par le handler bundle sur la file configurée (pas de commande console dédiée).

Pour un scénario de démonstration minimal (monorepo) : répertoire **`symfony/`**, puis `composer install`, `durable:schema:init`, et `durable:sample GreetingWorkflow` comme dans le README principal.

## Environnement de test

Vous pouvez surcharger la configuration (comme dans l’exemple avec `when@test:`) pour basculer vers `in_memory` et simplifier les tests PHPUnit.

## Suite

- Connexion Doctrine dédiée et **MySQL non bufferisé** : [DBAL : connexion et MySQL]({{< relref "dbal-et-mysql" >}}).  
- **Temporal** comme backend de journal : [Temporal avec Durable]({{< relref "temporal" >}}).
