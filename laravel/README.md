# La maquette Laravel

Une application Laravel 12 ordinaire — `composer create-project laravel/laravel` — qui **sert** un
service Nexus à la démonstration à quatre applications.

C'est ce qu'elle sert qui compte. Les trois autres maquettes prouvaient qu'appeler ne demande rien à
l'hôte ; celle-ci est la première à montrer l'autre moitié **hors du conteneur de Symfony** : un
gestionnaire déclaré par `config/durable.php`, un workflow qui remplit une opération, et un worker
Nexus lancé par `php artisan`.

| | |
|---|---|
| namespace | `demo-laravel` |
| sert | `livraison` — `planifier` (tout de suite), `expedier` (par un workflow) |
| appelle | `stock`, depuis le workflow qui remplit `expedier` |
| backend | `temporal` — servir du Nexus l'exige, c'est la grappe qui route |
| PHP | 8.2, la seule version du poste qui ait `grpc` **et** `pdo_sqlite` |

## Ce qu'il a fallu écrire, et ce qu'il n'a pas fallu

Deux classes, et **six lignes de configuration** :

```php
// config/durable.php
'backend' => env('DURABLE_BACKEND', 'temporal'),
'temporal' => ['dsn' => env('DURABLE_DSN')],
'workflows' => [App\Durable\Workflow\ExpedierWorkflow::class],
'nexus' => ['handlers' => [
    App\Durable\Nexus\LivraisonHandler::class => LivraisonContract::class,
]],
```

Rien d'autre : ni provider à écrire, ni commande, ni passe de compilation.
`gplanchat/durable-laravel` apporte `durable:nexus-worker` et `durable:temporal-worker`, et
`DeclaredNexusOperations` fait le travail que `NexusHandlerPass` fait côté Symfony — lire le
contrat, tenir entre la signature du gestionnaire et ce que le registre appelle.

⚠ **Ce qu'il ne fait pas, et que Symfony fait :** refuser au démarrage un workflow dont un paramètre
ne porte pas le nom déclaré par le contrat. La passe de compilation compare les deux listes ;
`config/durable.php` ne compare rien. Sur cet hôte, un renommage d'un seul côté donne `null` au
workflow, sans erreur et sans trace.

## Le workflow qui sert **et** appelle

`ExpedierWorkflow` remplit `livraison/expedier`, attend six secondes de préparation en entrepôt,
puis **appelle `stock/reserver` chez la boutique Sylius** avant de sortir la marchandise. Une même
exécution porte donc une opération Nexus servie et une opération Nexus appelée, dans le même
journal.

L'appel est sans risque parce que `reserver` est idempotente par identifiant de commande : la
boutique relit la décision prise à la commande au lieu d'en prendre une nouvelle — c'est pourquoi
les lignes passées sont vides.

## Lancer

Le banc n'a pas de grappe à lui, ni de base à installer : SQLite suffit, et le DSN de la
démonstration entre par l'environnement.

```bash
cd laravel
php8.2 composer install
php8.2 artisan migrate            # sqlite : la table de cache porte l'idempotence de planifier

DURABLE_DSN='temporal://127.0.0.1:7239?namespace=demo-laravel&nexus_task_queue=demo-laravel-nexus&tls=0' \
  php8.2 artisan durable:nexus-worker      # poll les tâches Nexus
DURABLE_DSN='…' php8.2 artisan durable:temporal-worker  # fait avancer ExpedierWorkflow
```

`demo/lancer.sh` démarre les deux avec les bonnes valeurs, en même temps que les six autres
processus. Les prérequis de l'ensemble sont dans [`demo/README.md`](../demo/README.md).

## Ce qu'elle n'est pas

**Un tableau de bord.** `gplanchat/durable-filament` en portera un ; ce banc n'a pas d'interface, et
son `welcome.blade.php` est celui de Laravel.

**Une application métier.** Elle ne modélise pas une logistique : elle sert un contrat de
démonstration, et son gestionnaire choisit un créneau par une règle de trois lignes.
