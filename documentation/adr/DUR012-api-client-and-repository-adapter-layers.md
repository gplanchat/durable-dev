# DUR012 — Couche client API et adaptateurs repository

## Statut

Accepté

## Contexte

Les **repositories** Command et Query (DUR002) parlent à l’**API Temporal** via un transport (gRPC/HTTP selon l’implémentation). Mélanger **appels réseau**, **sérialisation**, **mapping** vers les modèles du composant et **règles métier** dans une seule classe rend les tests difficiles et les erreurs opaques.

## Décision

On distingue **deux responsabilités** complémentaires :

### 1. Client de protocole (couche « API » / transport)

- Gère la **conversation** avec le serveur Temporal : authentification si besoin, **requêtes/réponses** brutes ou quasi brutes, codes de statut, deadlines.
- Ne contient **pas** la logique métier du domaine hôte ; peut encapsuler retries de bas niveau **uniquement** s’ils ne contredisent pas DUR011.
- Reste **substituable** dans les tests par un fake ou un mock de bas niveau.

### 2. Adaptateur repository

- **Implémente** les ports Command/Query du composant Durable.
- **Orchestre** les appels au client : une opération repository peut enchaîner plusieurs appels si nécessaire.
- **Mappe** les structures transportées vers les **types du composant** (identifiants, DTO internes, erreurs traduites).
- **Traduit** les échecs réseau ou protocolaires en erreurs du modèle Durable / domaine (DUR011).

### Principes

- **Une seule direction de dépendance** : adaptateur → client → réseau ; le domaine applicatif dépend des **interfaces** de repository, pas du client.
- **Pas de fuite** des types du client HTTP/gRPC dans les signatures **publiques** des ports stables du composant.
- La **sérialisation** des payloads applicatifs suit DUR007 ; le client peut avoir sa propre enveloppe (headers, enveloppes gRPC).

## Conséquences

- Les tests d’intégration peuvent cibler l’adaptateur avec un **client factice** ; les tests du client avec un **serveur de test** ou des réponses enregistrées.
- Les évolutions du protocole Temporal sont **localisées** dans le client et les mappers, pas dans toute la codebase.
