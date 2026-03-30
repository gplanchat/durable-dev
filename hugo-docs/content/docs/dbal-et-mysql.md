---
title: "DBAL : connexion et MySQL"
weight: 25
---

Le bundle Durable persiste le journal d’événements, les métadonnées de workflow, le lien parent–enfant optionnel, et peut utiliser DBAL pour d’autres adaptateurs. Tout cela transite par **Doctrine DBAL**.

## Connexion dédiée (`durable.dbal_connection`)

**Problème** : partager la même connexion PDO que le reste de l’application peut mélanger transactions, curseurs et gros résultats avec le parcours du journal.

**Décision** : le bundle expose **`durable.dbal_connection`** : un **nom de connexion Doctrine** (par défaut `default`) utilisé pour tous les adaptateurs DBAL Durable. Un alias de service **`durable.dbal.connection`** pointe vers `doctrine.dbal.{nom}_connection`.

### Déclarer une deuxième connexion

```yaml
# config/packages/doctrine.yaml
parameters:
    env(DATABASE_URL): '%env(resolve:DATABASE_URL)%'
    env(DURABLE_DATABASE_URL): '%env(resolve:DATABASE_URL)%'

doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            durable:
                url: '%env(resolve:DURABLE_DATABASE_URL)%'
```

Puis dans la config Durable :

```yaml
# config/packages/durable.yaml
durable:
    dbal_connection: durable
```

Effets possibles :

- **Même DSN** pour `default` et `durable` : deux instances **distinctes** de `Connection` / PDO — pas de curseur ou transaction partagés.  
- **DSN différent** : isolation physique des tables Durable.

La commande `php bin/console durable:schema:init` crée les tables sur cette connexion.

### Messenger et DBAL

Les transports Messenger `doctrine://...` sont **indépendants** de `durable.dbal_connection`. Si vous voulez aligner files d’attente et persistance Durable sur le même pool, configurez explicitement les transports (par ex. `doctrine://durable?...`).

## Lectures non bufferisées (MySQL)

Pour des **historiques très volumineux** sur **MySQL**, le pilote peut **charger tout le jeu de résultats en mémoire côté client** même si le code PHP itère ligne par ligne. Le moteur Durable lit le flux via `Result::iterateAssociative()`, mais le **buffer du pilote** peut encore saturer la mémoire.

Sur la connexion **`durable`** uniquement, vous pouvez désactiver les requêtes bufferisées :

```php
\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false
```

Exemple avec DoctrineBundle (attribut numérique ou constante PHP) :

```yaml
doctrine:
    dbal:
        connections:
            durable:
                url: '%env(resolve:DURABLE_DATABASE_URL)%'
                driver: pdo_mysql
                options:
                    1000: false   # PDO::MYSQL_ATTR_USE_BUFFERED_QUERY (pdo_mysql / mysqlnd)
```

ou :

```yaml
                options:
                    !php/const PDO::MYSQL_ATTR_USE_BUFFERED_QUERY: false
```

Si vous ne pouvez pas ajouter d’`options` au DSN, utilisez un **middleware** ou un **wrapper** DBAL qui fixe l’attribut PDO après connexion, **sur la connexion `durable` seulement**.

### Contraintes importantes

1. Tant qu’un résultat **non bufferisé** est ouvert sur une connexion, **ne lancez pas** une autre requête sur **la même** connexion avant d’avoir entièrement consommé l’itérateur (ou fermé le résultat). D’où l’intérêt d’une connexion **réservée** au journal.  
2. **SQLite** et **PostgreSQL** se comportent différemment ; ce réglage est **spécifique à MySQL**.  
3. Les **proxies / poolers** peuvent bufferiser malgré tout : validez en environnement réel.

## Références dans le dépôt

- Implémentation : `Gplanchat\Durable\Bundle\DependencyInjection\Configuration` — clé `dbal_connection`  
- Décision détaillée : ADR016 — *Dedicated DBAL connection and unbuffered reads* (`documentation/adr/`)
