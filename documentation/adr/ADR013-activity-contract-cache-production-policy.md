# ADR013 — Politique cache PSR-6 des métadonnées de contrat d’activité (prod)

## Statut

Accepté

## Contexte

`ActivityContractResolver` peut recevoir un pool PSR-6 (`CacheItemPoolInterface`) pour mettre en cache la carte *nom de méthode → nom d’activité* dérivée des attributs `#[Activity]` / `#[ActivityMethod]`. Le bundle expose `durable.activity_contracts.cache` (ID de service) et un cache warmer optionnel sur une liste de classes de contrat.

En production, on peut se retrouver dans l’un des cas suivants :

- aucun pool injecté (`null`) : pas de cache applicatif ;
- pool injecté mais froid (première requête, nouveau déploiement, TTL expiré, nouvelle clé) : *miss* puis remplissage.

## Décision

1. **Absence de pool PSR-6** : comportement **volontairement supporté**. Le résolveur s’appuie sur la réflexion à chaque appel à `resolveActivityMethods()`. C’est acceptable lorsque le volume d’appels reste faible ou en environnements de développement ; en charge, préférer un pool dédié.

2. **Miss avec pool configuré** : sur *miss*, le comportement est **réflexion puis écriture** (`getItem` / `set` / `save`). Aucun échec dur n’est imposé si le cache est vide : l’application reste fonctionnelle au prix d’un coût CPU ponctuel.

3. **Recommandation prod** : configurer un pool PSR-6 + lister les contrats dans `activity_contracts.contracts` pour le **cache warmer** afin de limiter les rafales de réflexion au démarrage ou après déploiement (voir `ActivityContractCacheWarmer`).

## Conséquences

- Pas de changement de code obligatoire : la politique documente le contrat actuel.
- Les revues d’archi peuvent renvoyer ici pour trancher « faut-il rendre le cache obligatoire en prod ? » — réponse actuelle : non, mais fortement recommandé sous charge.

## Références

- `src/Durable/Activity/ActivityContractResolver.php`
- `src/DurableBundle/DependencyInjection/DurableExtension.php` (`registerActivityContractResolver`, `registerActivityContractCacheWarmer`)
- ADR012 (métadonnées d’activité et analyse statique)
