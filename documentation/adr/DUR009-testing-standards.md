# DUR009 — Règles d'écriture des tests

## Statut

Amendé (DUR009-v2) — ajout des conventions `FakeWorkflowServiceClient`, `ActivitySpy`, `WorkflowTestEnvironment`, `DurableBundleTestTrait` (2026-04-04)

## Contexte

Le composant Durable repose sur des **comportements déterministes** (rejeu, idempotence des workflows) et des **adaptateurs** (Temporal, In-Memory). Les tests doivent **protéger** ces contrats sans fragilité, sans dépendre de l'aléa ou du temps réel, et sans accumuler des doubles de test opaques.

## Décision

### Cadre et outillage

- **PHPUnit** est le framework de test par défaut pour le code PHP du projet.
- Les tests **doivent** être **déterministes** : pas de dépendance à l'heure réelle, aux identifiants aléatoires non contrôlés, ni aux réseaux externes non simulés.

### Organisation

- **Un cas de test** (méthode) **une intention** : nom de test lisible décrivant le comportement attendu.
- **Données** : préférer des **fixtures** ou **builders** explicites plutôt que des littéraux magiques répétés ; facteurs communs dans des méthodes ou classes de données de test dédiées lorsque cela clarifie la lisibilité.

### Doubles et isolation

- **Test doubles** (fakes, stubs, spies) **préférés** aux mocks généralistes lorsque la lisibilité et le contrôle du comportement sont meilleurs.
- Les **mocks** ne sont **pas** interdits, mais leur usage doit rester **limité** aux frontières où l'injection d'un comportement est le plus simple.
- Les tests du **domaine** et des **ports** ne doivent **pas** dépendre d'un cluster Temporal réel : utiliser le backend **In-Memory** (DUR005) ou des doubles dédiés.

#### Doubles fournis par le composant

| Classe | Package | Usage |
|---|---|---|
| `Gplanchat\Durable\Testing\ActivitySpy` | `gplanchat/durable` | Fake contrôlable pour une activité. Permet de piloter la valeur de retour, de forcer une exception et de vérifier les appels. |
| `Gplanchat\Durable\Testing\WorkflowTestEnvironment` | `gplanchat/durable` | Façade agrégeant les briques in-memory (`InMemoryEventStore`, `InMemoryWorkflowMetadataStore`, `ExecutionEngine`…) pour les tests unitaires de workflows. |
| `Gplanchat\Durable\Testing\DurableTestCase` | `gplanchat/durable` | Classe de base PHPUnit préconfigurée. Instancie un `WorkflowTestEnvironment` et expose des méthodes utilitaires (`runWorkflow()`, `assertWorkflowResult()`…). |
| `Gplanchat\Bridge\Temporal\Testing\FakeWorkflowServiceClient` | `gplanchat/durable-bridge-temporal` | Implémentation in-process du service gRPC Temporal. Utilisée pour tester le bridge Temporal sans cluster réel. |
| `Gplanchat\Durable\Bundle\Testing\DurableBundleTestTrait` | `gplanchat/durable-bundle` | Trait PHPUnit pour `KernelTestCase`. Expose `dispatchWorkflow()`, `drainMessengerUntilSettled()`, `assertWorkflowResultEquals()`, `getEventStoreService()`. |

### Temporal et workflows

- **Pas de SDK Temporal officiel** dans les tests non plus (DUR006) : les tests valident les **abstractions** du composant, pas un client tiers interdit.
- Les scénarios de **rejeu** et d'**idempotence** (DUR003) sont couverts par des tests **répétables** (même entrée → même historique / même décision simulée).
- **`FakeWorkflowServiceClient`** est le double de référence pour les tests du bridge Temporal : il simule l'API gRPC en mémoire sans dépendre d'un cluster. Les tests PHPUnit du bridge **doivent** l'utiliser à la place d'un client gRPC réel.
- Toute méthode publique de `FakeWorkflowServiceClient` non encore implémentée lève `\BadMethodCallException` pour signaler clairement un usage non couvert.

### Style et conventions

- Le code des tests suit le **PER** (DUR008) : nommage des classes de test, méthodes, et structure des fichiers conformes au projet.

### Non-objectifs de cette ADR

- La **proportion** des types de tests (unitaires, intégration, bout-en-bout) est définie dans **DUR010** (pyramide des tests).

## Conséquences

- La CI **devrait** exécuter la suite PHPUnit sur les changements pertinents et exiger une couverture minimale ou des critères de qualité fixés par les mainteneurs.
- Les META documents peuvent détailler des patterns (fixtures, builders) sans dupliquer les principes ci-dessus.
- `DurableTestCase`, `ActivitySpy` et `WorkflowTestEnvironment` sont livrés dans `src/Durable/Testing/` et disponibles **sans** le bundle Symfony — ils conviennent aussi à une intégration sans framework.
- `DurableBundleTestTrait` est livré dans `src/DurableBundle/Testing/` et requiert `symfony/framework-bundle` et `symfony/messenger`.
