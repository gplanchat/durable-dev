# Standards PHPUnit

ADR003-phpunit-testing-standards
===

Introduction
---

Ce **Architecture Decision Record** définit les standards obligatoires pour l'écriture des tests PHPUnit dans le projet Durable. Ces standards favorisent des tests maintenables, fiables et isolés, avec un usage minimal des mocks PHPUnit.

Philosophie de tests
---

Les tests _DOIVENT_ privilégier les implémentations réelles plutôt que les mocks. Cela conduit à :

- Des scénarios de test plus réalistes
- Une meilleure couverture d'intégration
- Des tests moins fragiles
- Un refactoring plus facile

Règle : Minimiser les mocks PHPUnit
---

Les tests _DOIVENT_ réduire au strict minimum l'utilisation des mocks fournis par PHPUnit (`createMock`, `createStub`, `getMockBuilder`, etc.).

### Alternatives recommandées

1. **Implémentations réelles** : utiliser les services réels lorsque possible
2. **Implémentations in-memory** : ex. `InMemoryEventStore`, `InMemoryActivityTransport`
3. **Test doubles dédiés** : classes qui implémentent les interfaces, dans des fichiers séparés
4. **Composants Symfony** : `MockHttpClient`, `MockResponse` pour les tests HTTP

### Exceptions

Les mocks PHPUnit _PEUVENT_ être utilisés uniquement pour :

- Tester des conditions d'erreur difficiles à reproduire
- Mocker des services externes indisponibles en test
- Vérifier des appels spécifiques lorsque strictement nécessaire

Activités et workflows
---

Pour les tests d'activités :

- Enregistrer des handlers réels via `RegistryActivityExecutor`
- Utiliser `InMemoryEventStore` et `InMemoryActivityTransport` pour les tests unitaires
- Pour les tests d'intégration avec Messenger, utiliser les transports de test Symfony

### `DurableTestCase` (stack in-memory)

Toute la stack de test in-memory (event store, transport, `ExecutionRuntime`, helpers `stack()` / `executionId()`, assertions sur le journal distribué et sur la file d’activités) est regroupée dans **`Gplanchat\Durable\Tests\Support\DurableTestCase`**. Les tests de workflows / durable **étendent** cette classe de base ; il n’y a plus de trait dédié.

Exemple
---

```php
final class WorkflowTest extends TestCase
{
    public function testWorkflowCompletesWithActivityResult(): void
    {
        $eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $executor->register('echo', fn (array $p) => $p['msg'] ?? '');
        $runtime = new ExecutionRuntime($eventStore, $transport, $executor);
        $engine = new ExecutionEngine($eventStore, $runtime);

        $result = $engine->start((string) Uuid::v7(), function (ExecutionContext $ctx, ExecutionRuntime $rt) {
            return $env->await($env->activity('echo', ['msg' => 'hello']));
        });

        self::assertSame('hello', $result);
    }
}
```

Métadonnées PHPUnit (PHPUnit 11 → 12)
---

Les annotations dans les docblocks (`@test`, `@covers`, `@group`, etc.) sont **dépréciées** dans PHPUnit 11 et seront retirées dans PHPUnit 12. Le projet utilise les **attributs PHP 8** du namespace `PHPUnit\Framework\Attributes` (ex. `#[Test]`, `#[Group('…')]`).

### Couverture de code (métadonnées)

- Préférer **`#[CoversClass(ClassName::class)]`** (répétable) pour le code sous test principal.
- L'API workflow publique est **`WorkflowEnvironment`** ; les workflows reçoivent `$env` et appellent `$env->await()`, `$env->activityStub()`, etc.
- Éviter **`#[CoversNothing]`** sauf cas exceptionnel (tests purement infra sans SUT dans `src/`).

### Style de code des tests

- Scripts Composer : `composer cs` (fix), `composer cs:check` (dry-run), `composer test`, `composer test:coverage`. Voir ADR002.

### Montée vers PHPUnit 12

- Checklist projet : [OST002 — PHPUnit 12](../ost/OST002-phpunit12-upgrade-checklist.md).

CI (GitHub Actions)
---

- Workflow : `.github/workflows/ci.yml`.
- **PHP** : matrice **8.2** et **8.3** ; job `qa` exécute `composer cs:check` puis `composer test` (`phpunit --strict-coverage`).
- **Couverture** : job séparé sous **PHP 8.2** avec **PCOV** (`pcov.directory=.`), puis `composer test:coverage` (rapport texte, filtre `src/`).
- **Locale** : `composer test:coverage` nécessite **PCOV** ou **Xdebug** ; sinon PHPUnit émet un avertissement (suggestion Composer : `ext-pcov`). Voir [PRD004](../prd/PRD004-ci-github-actions.md).
- **Seuil minimal de lignes** : non imposé pour l’instant (rapport consultable dans les logs CI) ; à ajouter ultérieurement si un outil ou une option PHPUnit stable est retenu.

Références
---

- [PHPUnit Test Doubles](https://docs.phpunit.de/en/10.5/test-doubles.html)
- [Symfony HTTP Client Testing](https://symfony.com/doc/current/http_client.html#testing-request-data)
- [ADR001 - Processus ADR](ADR001-adr-management-process.md)
