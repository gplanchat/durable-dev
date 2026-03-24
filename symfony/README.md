# Application exemple Durable + Symfony

Illustre `gplanchat/durable` avec **Messenger**, **Doctrine DBAL** (SQLite par défaut) et des workflows en classe (`App\Durable\Workflow\`).

## Prérequis

- PHP 8.2+
- Composer — dépendances dans **`symfony/vendor/`** (défaut Composer). Exécuter **`composer install`** depuis ce dossier `symfony/`.
- Le package **`gplanchat/durable`** est pris sur le **dépôt parent** (`composer.json` : repository `path` → `..`, lien symbolique). Pas d’artefact zip dans le dépôt ; pour un clone « app seule », utiliser une dépendance Packagist ou un `path` / `VCS` pointant vers votre copie du composant.

Après un changement de répertoire `vendor` (ex. passage d’un ancien `durable-symfony-vendor` externe), vider le cache : **`rm -rf var/cache/*`** puis **`php bin/console cache:clear`**.

## Installation

```bash
cd symfony
composer install
```

## Base de données (tables journal / métadonnées / lien parent-enfant)

En environnement **dev** (`config/packages/durable.yaml`), le journal Durable et le lien parent↔enfant async utilisent le **DBAL** (même connexion que Messenger Doctrine).

Initialisation **idempotente** :

```bash
php bin/console durable:schema:init
```

Puis lancer les workers et les samples (voir README racine du composant).

## Tests PHPUnit (cette app)

```bash
cd symfony
composer test
# ou : php bin/phpunit
```

Les tests utilisent `sqlite:///:memory:` (voir `phpunit.xml.dist`). Ils couvrent **`durable:schema:init`** (idempotence) et **`durable:sample`** (GreetingWorkflow, ParallelGreetingWorkflow, ParentCallsEchoChildWorkflow, TimerThenTickWorkflow, SideEffectRandomIdWorkflow).
