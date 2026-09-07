# fix/middlewares-bus-choisis

- **Chantier** : M13 de l'audit. `RegisterDurableMiddlewarePass` insère les middlewares du bundle en
  tête de **tous** les bus Messenger de l'application, sans échappatoire : le verrou DBAL et le
  middleware de profil s'appliquent au bus de commandes métier d'un utilisateur qui n'a rien
  demandé. Ajout d'un nœud `durable.messenger.buses` ; vide = tous, comme aujourd'hui.
- **Entrées** : `Configuration`, `DurableExtension`, `RegisterDurableMiddlewarePass`, un test neuf
  (pas `DurableMiddlewareReachesTheBusTest`, que la PR #277 modifie déjà), la référence de
  configuration.
- **État** : en revue — PR #279.
