# DUR017 — Observabilité et opérations

## Statut

Accepté

## Contexte

Les workflows durables sont **longs**, **distribués** et **retentés**. Sans **logs** structurés, **métriques** et **traces**, le diagnostic des incidents et le suivi métier sont impraticables. L’**UI Temporal** et l’API du serveur fournissent une visibilité native ; le composant et les **activités** doivent compléter cette visibilité côté application.

## Décision

### Trois piliers

1. **Journaux** : événements discrets avec contexte (identifiant de workflow, run, type d’activité, durée) — conformité **PSR-3** pour les loggers injectés dans le code applicatif et les activités.
2. **Métriques** : compteurs, histogrammes de latence, taux d’erreur par type d’opération ; corrélation possible avec l’**identifiant de workflow** en libellé lorsque le cardinal le permet.
3. **Traces** : lorsque l’hôte active la **distributed tracing** (OpenTelemetry ou équivalent), propager les identifiants sur les appels **activités → services externes** (DUR012).

### Règles côté activités

- **Structured logging** : champs stables (clés de contexte), pas de données sensibles en clair (tokens, secrets).
- Journaliser les **échecs** avec code ou type d’erreur **métier** vs **système** (DUR011).

### Règles côté workflow

- Pas de logique d’I/O ; les **logs** dans le chemin du workflow doivent rester **déterministes** ou absents du code utilisateur — le runtime peut journaliser des **jalons** sans briser le rejeu (DUR003).

### Exploitation

- **Temporal Web UI** : inspection des historiques, recherche par identifiants ; s’appuyer sur les **search attributes** ou métadonnées lorsque le produit les définit (hors périmètre strict du noyau si optionnel).
- **Runbooks** : procédures pour annulation, signal manuel, réparation — documentées au niveau produit ; ce ADR fixe le **principe** de traçabilité.

### Alignement tests / prod

- Les niveaux de log en CI peuvent être réduits ; les **mêmes** points de corrélation (IDs) doivent rester testables en intégration (DUR017 + DUR015).

## Conséquences

- Les dépendances **logger** sont injectées ; pas de singleton global caché.
- L’**observabilité** ne remplace pas les **tests** mais accélère le diagnostic quand un test manque.
