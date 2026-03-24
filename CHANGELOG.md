# Changelog

## [Unreleased] — Ruptures API (parité Temporal)

### Changé (outillage)

- **README** — Démarrage rapide (~3 min) sur l’app `symfony/` ; activités documentées avec **`GreetingActivityInterface`** + **`GreetingActivityHandler`** (sans exemple `ActivityExecutor::register`).
- **App `symfony/`** — contrat d’exemple renommé **`GreetingActivityInterface`** ; `DurableSamplesConfigurator` délègue aux handlers (`GreetingActivityHandler`, `EchoActivityHandler`, `TickActivityHandler`) ; ajout de **`TickActivityHandler`**.
- **PHPUnit** — `@coversNothing` en docblock remplacé par `#[CoversNothing]` sur les tests E2E / helpers concernés (fin des *PHPUnit test runner deprecations* avec `composer test`).
- **App `symfony/`** — suppression du zip `local-packages/gplanchat-durable-*.zip` : la dépendance **`gplanchat/durable`** reste le repository **`path` → `..`** ; `symfony/local-packages/.gitignore` ignore tout artefact local.

### Supprimé

- **`src/functions.php`** — Fichier supprimé. L'autoload `files` pointant vers ce fichier a été retiré du `composer.json`.
- **Fonctions globales** `await()`, `parallel()`, `all()`, `any()`, `race()`, `delay()`, `timer()`, `sideEffect()`, `execute_child_workflow()`, `wait_signal()`, `wait_update()`, `async()` — Ces helpers n'existent plus. Utiliser les **méthodes** de `WorkflowEnvironment`.
- **Signature des handlers workflow** — Les callables ne reçoivent plus `(ExecutionContext $ctx, ExecutionRuntime $rt)` mais **`(WorkflowEnvironment $env)`**.
- **Enregistrement des workflows** — `WorkflowRegistry::register(string, callable)` supprimé. Utiliser `WorkflowRegistry::registerClass(WorkflowClass::class)` ou le tag Symfony `durable.workflow`.

### Changé

- **Point d'entrée workflow** — Toutes les opérations (await, timer, activity, childWorkflow, waitSignal, waitUpdate, etc.) passent par **`WorkflowEnvironment`** injecté au handler.
- **Activités** — API recommandée : `$env->activityStub(ActivityInterface::class)->methodName(...)` au lieu de `$env->activity('name', $payload)`.
- **Workflows enfants** — API recommandée : `$env->childWorkflowStub(ChildWorkflowClass::class)->run(...)` au lieu de `$env->executeChildWorkflow('WorkflowType', $input)`.

### Ajouté

- **`DurableChildWorkflowFailedException`** — Champs optionnels `workflowFailureKind`, `workflowFailureClass`, `workflowFailureContext` (alignés sur `ChildWorkflowFailed` / rejeu journal parent).
- **App exemple `symfony/`** — `vendor` par défaut sous **`symfony/vendor/`** (plus de `vendor-dir` forcé hors dépôt) ; repli optionnel vers `../../durable-symfony-vendor` dans `load_autoload_runtime.php` / bootstrap / `bin/phpunit` si présent. **`composer test`** : schéma + samples (**Greeting**, **ParallelGreeting**, **ParentCallsEchoChild**, **TimerThenTick**, **SideEffectRandomId**). **CI** : job **`symfony-sample`**.

- **`WorkflowEnvironment`** — Façade unique par exécution.
- **`#[Workflow]`, `#[WorkflowMethod]`** — Attributs pour les classes workflow.
- **`#[Activity]`, `#[ActivityMethod]`** — Attributs pour les contrats d'activité.
- **`#[SignalMethod]`, `#[QueryMethod]`, `#[UpdateMethod]`** — Attributs pour documenter les canaux signal/query/update.
- **`ActivityStub`**, **`ChildWorkflowStub`** — Proxies typés.
- **`WorkflowDefinitionLoader`** — Chargement des workflows par classe.
- **`ActivityContractResolver`** — Résolution des métadonnées d'activité (cache PSR-6).
- **`ActivityContractCacheWarmer`** — Pré-charge des contrats au warmup Symfony.
- **Tag `durable.workflow`** — Enregistrement automatique des workflows via le conteneur Symfony.
