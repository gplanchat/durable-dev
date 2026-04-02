# PLAN001 — Découpler Symfony Messenger du package `gplanchat/durable`

**Statut** : implémenté (découplage lib / bundle effectué).  
**Prérequis produit** : la **library** `gplanchat/durable` ne doit pas dépendre de `symfony/messenger` ; **Messenger** reste confiné au **`gplanchat/durable-bundle`**.

**Référence** : plan Cursor `lib-decouple-messenger` (todo associé).

---

## Objectif

- Retirer `symfony/messenger` de [`src/Durable/composer.json`](../../src/Durable/composer.json) et du [`composer.json`](../../composer.json) racine du monorepo si elle n’y est plus nécessaire pour autre chose que la lib.
- Déplacer l’adaptateur **`MessengerActivityTransport`** hors du namespace `Gplanchat\Durable\Transport` vers le bundle (ex. `Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport`).
- Conserver dans la **lib** les **DTO de transport** (`WorkflowRunMessage`, `DeliverWorkflowSignalMessage`, `DeliverWorkflowUpdateMessage`, `FireWorkflowTimersMessage`, `ActivityMessage`) : ce sont des objets métier sans import Symfony ; seule la **prise en charge Messenger** (enveloppes, sender/receiver) vit dans le bundle.
- Mettre à jour **DI**, **tests**, **analyse statique**, **documentation** (ADR004, ADR005, PRD001, README si besoin).

---

## Périmètre technique

| Élément | Action |
|--------|--------|
| [`MessengerActivityTransport.php`](../../src/DurableBundle/Transport/MessengerActivityTransport.php) | **Fait** : `Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport` ; `symfony/messenger` uniquement dans le bundle. |
| [`DurableExtension.php`](../../src/DurableBundle/DependencyInjection/DurableExtension.php) | Référencer la nouvelle classe du bundle pour `ActivityTransportInterface` quand `type: messenger`. |
| [`tests/integration/Durable/Messenger/MessengerActivityTransportTest.php`](../../tests/integration/Durable/Messenger/MessengerActivityTransportTest.php) | Mettre à jour `use` + emplacement éventuel (`tests/integration/Bundle/…` ou garder dossier `Messenger` avec import bundle). |
| [`documentation/adr/ADR004-ports-and-adapters.md`](../adr/ADR004-ports-and-adapters.md) | Adapter : adaptateur Messenger listé sous **bundle** ou « intégration Symfony ». |
| [`documentation/adr/ADR005-messenger-integration.md`](../adr/ADR005-messenger-integration.md) | Préciser : Messenger = **couche bundle** ; la lib expose uniquement le port `ActivityTransportInterface`. |
| [`psalm-baseline.xml`](../../psalm-baseline.xml) | Mettre à jour chemins si le fichier bouge. |
| Commentaires / noms (`ChildWorkflowDeferredToMessenger`, `asyncMessengerStart`, etc.) | **Optionnel** V2 : renommage sémantique type « distributed / async dispatch » pour éviter la confusion ; hors chemin critique si le comportement reste inchangé. |

---

## Ordre d’exécution recommandé

1. Créer la classe `MessengerActivityTransport` dans le bundle (copie puis suppression de l’ancienne), exports identiques (`implements ActivityTransportInterface`).
2. Brancher `DurableExtension` et toute référence dans le bundle.
3. Supprimer l’ancien fichier sous `src/Durable/Transport/`.
4. Retirer `symfony/messenger` de `src/Durable/composer.json` ; `composer update` / validation du monorepo.
5. Si le `composer.json` racine du monorepo ne nécessite `symfony/messenger` que pour la lib, le retirer ou le laisser **uniquement** si requis par d’autres paquets agrégés (sinon s’appuyer sur le bundle + app `symfony/`).
6. Lancer **PHPUnit** (dont test d’intégration Messenger), **PHPStan**, **Psalm**, **PHP-CS-Fixer** sur les fichiers touchés.
7. Mettre à jour les ADR / INDEX / README courts qui citent l’emplacement de `MessengerActivityTransport`.

---

## Critères d’acceptation

- `composer show symfony/messenger` depuis un projet qui ne dépend que de **`gplanchat/durable`** (sans bundle) : **aucune** installation de `symfony/messenger`.
- Le projet exemple [`symfony/`](../../symfony/) avec **bundle** : transports Messenger inchangés fonctionnellement (activités + reprise workflow).
- Aucune régression sur `DbalActivityTransport` / `InMemoryActivityTransport` (restent dans la lib).

---

## Todos (suivi)

- [x] Déplacer `MessengerActivityTransport` → bundle + wiring `DurableExtension`
- [x] Retirer `symfony/messenger` de `src/Durable/composer.json` (racine monorepo : conservé pour le bundle + suite de tests)
- [x] Mettre à jour tests d’intégration + baselines d’analyse statique
- [x] Réviser ADR004, ADR005, PRD001, README / INDEX
- [ ] (Optionnel) Renommage `ChildWorkflowDeferredToMessenger` / flag `asyncMessengerStart` pour vocabulaire neutre « distributed »

---

## Suite

Plan **exécuté** (autre session / agent). Le découplage lib / bundle est terminé ; toute intégration tierce (hors Symfony) repose sur les ports (`ActivityTransportInterface`, `EventStoreInterface`, etc.) documentés dans les ADR.
