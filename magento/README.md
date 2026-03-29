# Sandbox Magento (Durable)

Ce répertoire accueille une installation **Magento Open Source 2.4.8+** (PHP 8.2+) pour valider le module **`gplanchat/durable-magento`** (`../src/DurableModule/`).

## Prérequis

- Clés [repo.magento.com](https://repo.magento.com) dans `auth.json` à la racine de ce dossier.
- MySQL/MariaDB, Elasticsearch ou OpenSearch selon la version Magento.

## Installation (aperçu)

```bash
cd magento
composer create-project --repository-url=https://repo.magento.com/ magento/project-community-edition:^2.4.8 .
```

Ajoutez les dépôts **path** vers la lib et le module (chemins relatifs depuis `magento/`) :

```json
{
    "repositories": [
        { "type": "path", "url": "../src/Durable", "options": { "symlink": true } },
        { "type": "path", "url": "../src/DurableModule", "options": { "symlink": true } }
    ],
    "require": {
        "gplanchat/durable": "*",
        "gplanchat/durable-magento": "*"
    }
}
```

Puis :

```bash
composer require gplanchat/durable:@dev gplanchat/durable-magento:@dev
php bin/magento module:enable Gplanchat_DurableModule
php bin/magento setup:upgrade
```

## CLI activités (mode DBAL)

Après `setup:db-schema:upgrade` (tables `durable_*`) :

```bash
php bin/magento gplanchat:durable:activities:consume
```

Option `--max-messages` / `-m` pour limiter le nombre de messages traités.

## Compose optionnel (MySQL + OpenSearch)

```bash
docker compose -f ../docker-compose.magento-dev.yaml up -d
```

Adaptez `env.php` (DB sur `localhost:3307`, OpenSearch sur `localhost:9201`). Pour le mode **Temporal**, voir [ADR015](../documentation/adr/ADR015-magento-durable-module.md) (serveur / worker hors RoadRunner).
