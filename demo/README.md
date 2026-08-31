# La démonstration Nexus à quatre applications

Quatre applications, quatre namespaces Temporal, trois frameworks. Les quatre appellent, trois
servent.

| | `sylius/` — la boutique | `symfony/` — le métier | `magento/` — le banc Magento | `laravel/` — la logistique |
|---|---|---|---|---|
| namespace | `demo-boutique` | `demo-metier` | `demo-magento` | `demo-laravel` |
| sert | `stock` (`reserver`) | `facturation` (`verifier`, `encaisser`) | **rien** | `livraison` (`planifier`, `expedier`) |
| appelle | `facturation` | `stock` | les trois services | `stock`, **depuis le workflow qui sert** |
| ce qui déclare le gestionnaire | une balise sous `when@demo` | `#[AsNexusServiceHandler]` | — | six lignes de `config/durable.php` |
| profil qui **sert** | `APP_ENV=demo` — journal DBAL, tableau de bord inchangé | `APP_ENV=dev` — journal Temporal | — | backend `temporal` |
| profil qui **appelle** | `APP_ENV=demo_appelant` — journal Temporal | `APP_ENV=dev` | le DSN par `MAGENTO_DC_…` | le même |
| PHP | 8.3 | 8.3 | **8.2** | **8.2** |

Les quatre lisent le même paquet de contrats, `src/DurableDemoContracts/`. Rien d'autre ne circule
entre elles.

## Ce que la quatrième maquette ajoute

**Servir, hors du conteneur de Symfony.** Les deux gestionnaires des débuts étaient enregistrés par
`NexusHandlerPass` — une passe de compilation — et pollés par un transport Messenger. On pouvait en
conclure que la moitié servante de Nexus **était** du Symfony. La logistique la sert avec deux
classes et six lignes de `config/durable.php` : `DeclaredNexusOperations` fait le travail de la
passe, `php artisan durable:nexus-worker` celui du transport.

**Et elle appelle pendant qu'elle sert.** `ExpedierWorkflow` remplit `livraison/expedier` ; avant de
sortir la marchandise, il redemande son verdict à la boutique par `stock/reserver`, sur un endpoint
qui n'est pas le sien. Une même exécution porte donc une opération servie et une opération appelée —
et son identifiant est le **jeton de l'opération** qu'elle remplit, pas un nom choisi par
l'application.

⚠ **Ce que Symfony refuse au démarrage et que Laravel ne refuse pas.** La passe de compilation
compare les noms de paramètres du workflow remplissant à ceux du contrat ; un fichier de
configuration ne compare rien. Sur cet hôte, un renommage d'un seul côté donne `null` au workflow,
sans erreur et sans trace.

## Ce que la troisième maquette ajoute

**Elle n'a rien de ce que Nexus semblait demander.** Les deux premières partagent le conteneur de
Symfony, la passe de compilation qui enregistre les gestionnaires et le transport Messenger qui
tourne les workers ; un lecteur pouvait en conclure que Nexus était une fonctionnalité du bundle.
Le banc Magento câble ses services en `di.xml`, tourne son worker par `bin/magento durable:worker`
et lit son DSN dans `app/etc/env.php` — et il appelle les deux services sans qu'une ligne ait été
ajoutée au cœur, au pont Temporal ou à `gplanchat/durable-magento`.

**Parce qu'appeler ne demande rien, et que servir se câble une fois par hôte.**
`WorkflowEnvironment::nexusStub()` lit le contrat par réflexion, et le worker qui fait avancer
l'exécution est le même `WorkflowTaskRunner` dans les trois maquettes. Servir, en revanche, demande
à l'hôte d'enregistrer des gestionnaires et de poller une file Nexus : c'est pour cela que Magento
appelle et ne sert pas.

**Et l'ordre des appels compte.** `CommandeNexusWorkflow` demande d'abord tout ce qui peut dire non
— vérifier la facture, planifier la tournée, retenir le stock — et n'engage qu'ensuite : encaisser,
puis expédier. Les deux ordres inverses ont été écrits d'abord, et mesurés : une commande en USD
retenait le stock avant de se faire refuser la facture, et une commande de six colis était
**encaissée** avant que la logistique ne refuse de la porter. Aucun des trois contrats n'a
d'opération qui rende ce qu'il a pris ; l'ordre des appels est la seule compensation qu'il y ait.

## Ce qu'elle montre, et qu'un schéma ne montre pas

**Les deux formes, côte à côte, écrites pareil.** `CommandeWorkflow` appelle `verifier` puis
`encaisser` sur le même stub. La première revient en quelques millisecondes, servie par une méthode
que le métier a écrite ; la seconde prend une quinzaine de secondes, remplie par un workflow d'en
face. Le code de l'appelant ne distingue pas les deux, et c'est le sujet.

**L'attente ne tient rien d'ouvert.** Pendant une mise au point, le worker qui devait faire avancer
l'encaissement est resté éteint quatre minutes. L'opération est restée en
`NEXUS_OPERATION_STARTED`, l'appelant n'a rien consommé, et tout s'est terminé normalement quand le
worker est revenu. Aucune connexion, aucun processus, aucune transaction n'attendait.

**Une tâche Nexus est redélivrée.** Le gestionnaire de `stock` écrit son verdict dans
`app_durable_stock_reservation`, clé par identifiant de commande. Rejouer la même commande rend le
même verdict et ne retient pas de stock une seconde fois.

**Et cela vaut depuis n'importe quel appelant.** Refait depuis Magento, worker d'en face éteint : 49
secondes en `NexusOperationStarted`, puis le résultat nominal au retour du worker.

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
