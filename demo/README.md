# La démonstration Nexus à deux applications

Deux applications, deux namespaces Temporal, et chacune appelle l'autre.

| | `sylius/` — la boutique | `symfony/` — le métier |
|---|---|---|
| namespace | `demo-boutique` | `demo-metier` |
| sert | `stock` (`reserver`) | `facturation` (`verifier`, `encaisser`) |
| appelle | `facturation` | `stock` |
| profil qui **sert** | `APP_ENV=demo` — journal DBAL, tableau de bord inchangé | `APP_ENV=dev` — journal Temporal |
| profil qui **appelle** | `APP_ENV=demo_appelant` — journal Temporal | `APP_ENV=dev` |

Les deux lisent le même paquet de contrats, `src/DurableDemoContracts/`. Rien d'autre ne circule
entre elles.

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

### Les deux endpoints ne sont pas des résidus de test

`demo-boutique-stock` et `demo-metier-facturation` sont **stables**. La suite d'intégration en crée
d'autres sur le même cluster, nommés `durable-sv-…`, et les supprime à la fin de chaque test : ce
sont ceux-là qui sont éphémères. Un `nexus endpoint delete` de nettoyage ne doit pas emporter les
`demo-*` — sans eux, l'appelant part et le serveur ne sait pas où router, ce qui donne un échec qui
ne nomme ni le contrat ni le gestionnaire.

## Cinq processus, et non six

Le change en annonçait six : un worker Nexus, un de workflow et un d'activité par maquette. Il y en
a cinq. La boutique n'a pas de worker d'activité parce que `CommandeWorkflow` n'a pas d'activité —
il n'appelle que des opérations Nexus. Un sixième processus ne ferait que poller une file vide, et
la démonstration mentirait sur ce qu'elle demande.

| processus | maquette | profil | ce qu'il fait |
|---|---|---|---|
| `boutique-sert-stock` | `sylius/` | `demo` | poll les tâches Nexus de `demo-boutique` |
| `boutique-workflows` | `sylius/` | `demo_appelant` | fait avancer `CommandeWorkflow` |
| `metier-sert-facturation` | `symfony/` | `dev` | poll les tâches Nexus de `demo-metier` |
| `metier-workflows` | `symfony/` | `dev` | `ReserverStockWorkflow`, `EncaissementWorkflow` |
| `metier-activites` | `symfony/` | `dev` | l'activité de paiement |

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
