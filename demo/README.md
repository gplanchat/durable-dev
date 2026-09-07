# The four-application Nexus demonstration

Four applications, four Temporal namespaces, three frameworks. All four call, three serve.

| | `sylius/` — the shop | `symfony/` — the business | `magento/` — the Magento bench | `laravel/` — the logistics |
|---|---|---|---|---|
| namespace | `demo-shop` | `demo-business` | `demo-magento` | `demo-laravel` |
| serves | `stock` (`reserve`) | `billing` (`verify`, `charge`) | **nothing** | `delivery` (`schedule`, `ship`) |
| calls | `billing` | `stock` | all three services | `stock`, **from the workflow that serves** |
| what declares the handler | a tag under `when@demo` | `#[AsNexusServiceHandler]` | — | six lines of `config/durable.php` |
| profile that **serves** | `APP_ENV=demo` — DBAL journal, dashboard unchanged | `APP_ENV=dev` — Temporal journal | — | `temporal` backend |
| profile that **calls** | `APP_ENV=demo_caller` — Temporal journal | `APP_ENV=dev` | the DSN through `MAGENTO_DC_…` | the same one |
| PHP | 8.3 | 8.3 | **8.2** | **8.2** |

All four read the same contract package, `src/DurableDemoContracts/`. Nothing else travels between
them.

## What the fourth mockup adds

**Serving, outside the Symfony container.** The two original handlers were registered by
`NexusHandlerPass` — a compiler pass — and polled by a Messenger transport. One could conclude that
the serving half of Nexus **was** Symfony. The logistics serves it with two classes and six lines of
`config/durable.php`: `DeclaredNexusOperations` does the pass's work, `php artisan
durable:nexus-worker` the transport's.

**And it calls while it serves.** `ShipWorkflow` fulfils `delivery/ship`; before the goods leave, it
asks the shop for its verdict again through `stock/reserve`, on an endpoint that is not its own. One
execution therefore carries a served operation and a called operation — and its identifier is the
**operation's token**, not a name the application picked.

**And both serving hosts refuse the same mistake.** A fulfilling workflow whose required parameter
does not carry the contract's name makes the registration fail, on Symfony as on Laravel: the check
moved down into the core the day it had a second caller.

## What the third mockup adds

**It has none of what Nexus seemed to require.** The first two share the Symfony container, the
compiler pass that registers the handlers and the Messenger transport that runs the workers; a
reader could conclude that Nexus was a bundle feature. The Magento bench wires its services in
`di.xml`, runs its worker through `bin/magento durable:worker` and reads its DSN from
`app/etc/env.php` — and it calls both services without one line having been added to the core, to
the Temporal bridge or to `gplanchat/durable-magento`.

**Because calling requires nothing, and serving is wired once per host.**
`WorkflowEnvironment::nexusStub()` reads the contract by reflection, and the worker that advances the
execution is the same `WorkflowTaskRunner` in all three mockups. Serving, on the other hand, asks the
host to register handlers and to poll a Nexus queue: that is why Magento calls and does not serve.

**And the call order matters.** `OrderNexusWorkflow` asks first for everything that can say no —
verify the invoice, schedule the round, hold the stock — and only commits afterwards: charge, then
ship. Both reverse orders were written first, and measured: an order in USD held the stock before
having its invoice refused, and an order of six parcels was **charged** before the logistics refused
to carry it. None of the three contracts has an operation that gives back what it took; the call
order is the only compensation there is.

## What it shows, and a diagram does not

**Both shapes, side by side, written the same.** `OrderWorkflow` calls `verify` then `charge` on the
same stub. The first comes back in a few milliseconds, served by a method the business wrote; the
second takes some fifteen seconds, fulfilled by a workflow on the other side. The caller's code does
not tell the two apart, and that is the subject.

**The wait holds nothing open.** During one debugging session, the worker meant to advance the charge
stayed off for four minutes. The operation stayed in `NEXUS_OPERATION_STARTED`, the caller consumed
nothing, and everything finished normally when the worker came back. No connection, no process, no
transaction was waiting.

**A Nexus task is redelivered.** The `stock` handler writes its verdict into
`app_durable_stock_reservation`, keyed by order identifier. Replaying the same order returns the same
verdict and does not hold stock a second time.

**And that holds from any caller.** Repeated from Magento, with the facing worker off: 49 seconds in
`NexusOperationStarted`, then the nominal result when the worker came back.

## Prérequis

**Un serveur Temporal dont les API Nexus sont actives.** `temporal server start-dev` convient.
`temporalio/auto-setup:1.25.2` — l'image du `compose.yaml` de `symfony/` — répond
`Nexus APIs are disabled` à la création d'endpoint : elle ne suffit pas telle quelle.

**PHP 8.3 avec `ext-grpc`.** Mesuré en §0.1 du change : c'est la seule version qui l'ait sur le
poste de référence, et il lui manque `curl`, réclamé par `stripe/stripe-php` et le pilote Chrome —
deux paquets que la démonstration n'exécute pas. D'où :

```bash
cd sylius && composer install --ignore-platform-req=ext-curl
```

**PHP 8.2 pour les bancs Magento et Laravel**, et c'est la seule version du poste qui ait à la fois
`grpc`, `pdo_mysql`, `pdo_sqlite`, `curl`, `soap` et `intl` — ce que Mage-OS et Laravel exigent.
Les quatre maquettes tournent donc sur deux binaires PHP, et `demo/lancer.sh` a `PHP` (défaut
`php8.3`), `PHP_MAGENTO` et `PHP_LARAVEL` (défaut `php8.2`).

**Le banc Laravel installé.** Il n'a ni base à monter ni grappe à lui : SQLite suffit, et le DSN de
la démonstration entre par l'environnement.

```bash
cd laravel && php8.2 composer install && php8.2 artisan migrate
```

**Le banc Magento installé, ses conteneurs démarrés, et son autoloader à jour.** Le contrat partagé
entre par une entrée `autoload` de `magento/composer.json` — pas par un dépôt path comme chez les
deux autres, parce que les dépôts path du banc sont en `symlink: false` et copieraient le contrat.
Après un `git pull` qui touche `magento/composer.json` :

```bash
cd magento && php8.2 composer dump-autoload
docker compose up -d magento-db redis
```

Son DSN n'a pas à être changé : `demo/lancer.sh` passe celui de la démonstration par
`MAGENTO_DC_DURABLE__TEMPORAL__DSN`, la convention de Magento pour surcharger `app/etc/env.php` par
l'environnement. La grappe du `compose.yaml` du banc — `temporalio/auto-setup:1.25.2` — ne convient
pas : ses API Nexus sont désactivées.

**Une base pour la boutique.** PHP 8.3 n'a pas `pdo_mysql` sur ce poste, mais il a `pdo_pgsql` :

```bash
docker run -d --name durable-demo-pg \
  -e POSTGRES_USER=sylius -e POSTGRES_PASSWORD=sylius -e POSTGRES_DB=sylius_demo \
  -p 55432:5432 postgres:16-alpine

cd sylius
export DATABASE_URL='pgsql://sylius:sylius@127.0.0.1:55432/sylius_demo?serverVersion=16&charset=utf8'
bin/console doctrine:schema:update --force --complete
```

Puis deux variantes avec du stock, pour que la boutique ait quelque chose à retenir :

```sql
INSERT INTO sylius_product (id, code, created_at, enabled, variant_selection_method, average_rating)
VALUES (1, 'MUG', NOW(), true, 'choice', 0);
INSERT INTO sylius_product_variant
  (id, product_id, code, created_at, position, enabled, version, on_hold, on_hand, tracked, shipping_required, recurring)
VALUES (1, 1, 'MUG_BLUE', NOW(), 0, true, 1, 0, 5, true, true, false),
       (2, 1, 'MUG_RED',  NOW(), 1, true, 1, 0, 1, true, true, false);
```

## Lancer

```bash
temporal server start-dev --port 7239 --ui-port 8239     # si vous n'avez pas déjà un cluster

TEMPORAL_ADDRESS=127.0.0.1:7239 bin/demo-nexus           # namespaces + endpoints
TEMPORAL_ADDRESS=127.0.0.1:7239 demo/lancer.sh           # les workers
```

`bin/demo-nexus` et `demo/lancer.sh` impriment tous les deux les commandes d'appel avec les bonnes
valeurs. `demo/lancer.sh --etat` dit qui tourne, `--arreter` éteint tout.

### Si vous remettez le stock à zéro, redémarrez le worker de la boutique

Le worker Nexus de la boutique est un processus **long** : son `EntityManager` garde les
`ProductVariant` dans sa carte d'identité, et un `UPDATE … SET on_hold = 0` passé en SQL sous ses
pieds lui est invisible — il réécrit ensuite l'ancienne valeur augmentée. Deux relevés incohérents
en sont sortis pendant la mise au point de ce banc, `on_hold` à 4 et à 5 pour des commandes de 2 et
de 1. Après redémarrage du worker, le delta est exactement celui de la commande.

### Les trois endpoints ne sont pas des résidus de test

`demo-boutique-stock`, `demo-metier-facturation` et `demo-laravel-livraison` sont **stables**. La suite d'intégration en crée
d'autres sur le même cluster, nommés `durable-sv-…`, et les supprime à la fin de chaque test : ce
sont ceux-là qui sont éphémères. Un `nexus endpoint delete` de nettoyage ne doit pas emporter les
`demo-*` — sans eux, l'appelant part et le serveur ne sait pas où router, ce qui donne un échec qui
ne nomme ni le contrat ni le gestionnaire.

## Huit processus, et non douze

Le compte se fait par ce que chaque maquette a réellement à drainer, pas par une règle de trois
workers par application.

| processus | maquette | profil | ce qu'il fait |
|---|---|---|---|
| `boutique-sert-stock` | `sylius/` | `demo` | poll les tâches Nexus de `demo-boutique` |
| `boutique-workflows` | `sylius/` | `demo_appelant` | fait avancer `CommandeWorkflow` |
| `metier-sert-facturation` | `symfony/` | `dev` | poll les tâches Nexus de `demo-metier` |
| `metier-workflows` | `symfony/` | `dev` | `ReserverStockWorkflow`, `EncaissementWorkflow` |
| `metier-activites` | `symfony/` | `dev` | l'activité de paiement |
| `magento-workflows` | `magento/` | — | fait avancer `CommandeNexusWorkflow` |
| `logistique-sert-livraison` | `laravel/` | — | poll les tâches Nexus de `demo-laravel` |
| `logistique-workflows` | `laravel/` | — | fait avancer `ExpedierWorkflow` |

**Aucun worker d'activité pour la boutique, pour Magento ni pour la logistique**, et pour la même
raison dans les trois cas : leurs workflows n'ont pas d'activité — ce qu'ils attendent, ils
l'attendent d'un minuteur ou d'une opération servie ailleurs. Un worker de plus ne ferait que poller
une file vide, et la démonstration mentirait sur ce qu'elle demande.

**Aucun worker Nexus pour Magento** : il ne sert rien, donc rien à poller. C'est aussi pourquoi il
n'a pas d'endpoint — quatre namespaces, trois endpoints.

## Pourquoi la boutique a deux profils, et pourquoi `dev` n'en est pas un

Servir et appeler ne tiennent pas dans la même configuration Durable, et ce n'est pas un
contournement.

Un appel Nexus part d'un workflow, et un workflow ne peut ordonnancer une opération que si son
journal est le cluster : `EventStoreCommandBuffer` refuse en le disant, parce qu'un journal SQL n'a
pas de serveur à qui adresser l'ordonnancement. Or le profil qui **sert** garde son journal DBAL,
puisque c'est ce que lit le tableau de bord de la boutique.

Deux profils, donc, et c'est à cela que ressemble un vrai déploiement : le processus qui rend le
tableau de bord et celui qui exécute les workflows sont deux déploiements du même code.

Ni l'un ni l'autre n'est `dev`, et cela aussi a une raison mesurée. Un transport Messenger
`temporal://` déclaré sans DSN fait échouer `doctrine:schema:create` : l'écouteur de schéma de
Doctrine parcourt **tous** les transports, et le message qui sort est « Invalid temporal:// DSN »,
loin de Nexus et loin de Messenger. Un environnement sans cluster n'a donc ni DSN, ni transport, ni
gestionnaire — et `dev`, `prod` et `test` restent exactement ce qu'ils étaient.
