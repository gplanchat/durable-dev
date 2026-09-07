# Stratégie de test — Behat, pyramide, isolation et déterminisme

## Synthèse

La pyramide réelle est une base large et bien écrite (134 classes `*Test.php` dans `tests/unit`)
posée sur un milieu presque vide : 6 classes seulement dans `tests/integration/Durable`, contre 19
dans `tests/integration/Temporal` qui exigent toutes un serveur vivant et se sautent sans
`DURABLE_TEMPORAL_ADDRESS` — l'étage « intégration sans infrastructure externe » que DUR010 décrit
comme le milieu de la pyramide n'existe qu'à 6 classes pour ~20 000 lignes de cœur et de bundle. Le
rejeu déterministe est, lui, correctement gardé : `tests/unit/Durable/Replay/` (3 classes, 335 l.),
`DriverParityRegressionTest`, le palier replay de DUR041 joué par DBAL et Illuminate, et
`ReplayDivergenceGuardTest` contre un vrai serveur. La suite de conformité DUR041 est jouée par
trois backends sur quatre : mémoire, DBAL et Illuminate ont chacun leurs quatre sous-classes, **le
pont Temporal n'en a aucune**, alors que l'ADR se déclare « implemented for all four ports » et que
le docblock du cœur affirme le contraire (C1). La reprise après panne est modélisée honnêtement au
niveau intégration — `StepwiseWorkflowHarness` pilote un `ExecutionEngine` en mode `distributed`
avec start/resume explicites et relit le journal — mais une seule classe de test s'en sert. Deux
axes sont sains et méritent d'être dits en une ligne : la règle projet « jamais `run()` ni `fail()`
dans un `TestCase` » est tenue (les ~30 occurrences de `run()` sont toutes dans des classes-fixtures
de workflow déclarées après le `TestCase`, et `DurableTestCase` n'expose que des `assert*`), et la
dépendance à l'ordre de tableau est traitée explicitement — la suite des liens parent/enfant trie
avant de comparer et la suite du catalogue refuse d'affirmer un ordre que le port ne promet pas.

## Constats

### C1 — Le pont Temporal ne joue aucune suite de conformité DUR041, que l'ADR et le cœur déclarent pourtant jouée

- **Fichier** : `src/Durable/Testing/EventStoreReplayConformanceTestCase.php:24` ; `documentation/adr/DUR041-store-parity-is-a-suite-every-adapter-runs.md:5` et `:115`
- **Gravité** : majeur
- **Constat** : les douze sous-classes de conformité du dépôt se répartissent en quatre InMemory, quatre DBAL (`tests/unit/Bridge/Dbal/`) et quatre Illuminate (`tests/unit/Bridge/Illuminate/`) ; aucune classe, ni dans `tests/unit` ni dans `tests/integration`, n'étend une `*ConformanceTestCase` pour un adaptateur Temporal. Trois adaptateurs Temporal sont pourtant concernés : `src/Bridge/Temporal/TemporalJournalEventStore.php`, `src/Bridge/Temporal/Store/TemporalReadThroughEventStore.php` et `src/Bridge/Temporal/Store/TemporalWorkflowRunCatalog.php`. Le docblock cité affirme que « les deux stores Temporal » étendent `EventStoreConformanceTestCase`, l'ADR se déclare « implemented for all four ports » et annonce en conséquence que « Temporal's read-through store gets checked for the first time » : les trois affirmations sont fausses, et la coupure « visible plutôt qu'oubliée » que DUR041 revendique n'existe donc pas.
- **Amont** : `symfony/symfony`, `src/Symfony/Component/Cache/Tests/Adapter/AdapterTestCase.php` — cas abstrait livré que *chaque* test d'adaptateur de cache étend, la mécanique exacte que DUR041 copie ; l'invariant amont est qu'un adaptateur sans sous-classe est un adaptateur non prouvé. <https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/Cache/Tests/Adapter/AdapterTestCase.php>

> **Note d'édition.** Une des trois affirmations reprochées à DUR041 ne l'était pas à raison : le
> statut « implemented for all four ports » parle des **ports**, et les quatre ont bien leur suite.
> Ce qui était faux, ce sont les deux énoncés au présent de l'indicatif sur les adaptateurs — « they
> run this tier there » et « Temporal's read-through store gets checked for the first time » — plus
> le docbloc d'`EventStoreReplayConformanceTestCase`. Le reste du constat tient, et le décompte des
> trois adaptateurs concernés est exact. DUR041 et le docbloc portent désormais l'état réel.

- **Correctif** : ajouter trois sous-classes dans `tests/integration/Temporal/` (elles y disposent déjà du garde `markTestSkipped` de `TemporalServerTestCase`), ou corriger le statut de DUR041 et le docblock pour que « trois backends sur quatre » soit un fait écrit plutôt qu'un écart silencieux.

### C2 — La conformité des deux backends SQL n'est prouvée que contre SQLite en mémoire, dans la suite `unit`

- **Fichier** : `tests/unit/Bridge/Dbal/DbalEventStoreConformanceTest.php:21` ; `.github/workflows/ci.yml:172`
- **Gravité** : majeur
- **Constat** : les huit sous-classes de conformité SQL ouvrent toutes un SQLite en mémoire — `DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])` côté DBAL, `$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', …])` côté Illuminate (`tests/unit/Bridge/Illuminate/IlluminateEventStoreConformanceTest.php:28`) ; aucune ne tourne contre MySQL ou PostgreSQL. Le dépôt sait déjà que cette base est insuffisante : le commentaire du job `sylius-shop` enregistre que « un vrai MySQL […] a révélé deux fautes que SQLite avalait — un booléen lié sans type, et un upsert qui comptait les lignes affectées par un UPDATE ». Accessoirement, ces huit classes de `tests/unit` ouvrent une base et créent un schéma : par la définition de DUR010 (« no database ») elles appartiennent au milieu de la pyramide, pas à sa base — et une dizaine d'autres fichiers de `tests/unit/Bridge/` référencent le même SQLite en mémoire.
- **Amont** : `doctrine/dbal` livre un workflow CI **par plateforme** — `.github/workflows/phpunit-sqlite.yml`, `phpunit-mysql.yml`, `phpunit-mariadb.yml`, `phpunit-postgres.yml`, `phpunit-oracle.yml`, `phpunit-sqlserver.yml` — soit la même suite rejouée contre chaque moteur, précisément parce qu'un moteur ne parle pas pour les autres. <https://github.com/doctrine/dbal/tree/4.3.x/.github/workflows>
- **Correctif** : paramétrer `createEventStore()` par une DSN d'environnement (SQLite par défaut) et ajouter au job `qa` un service MySQL qui rejoue la seule testsuite de conformité — le coût est un conteneur, pas une matrice.

### C3 — Le milieu de la pyramide est quasi absent, et la testsuite `integration` mélange deux étages

- **Fichier** : `phpunit.xml:35`
- **Gravité** : majeur
- **Constat** : la testsuite `integration` pointe `tests/integration` en bloc, commentée « Contre un vrai serveur Temporal ». Elle contient en réalité deux populations : 19 classes dans `tests/integration/Temporal/` qui exigent toutes `DURABLE_TEMPORAL_ADDRESS` et `ext-grpc` (étage haut de DUR010), et 6 classes dans `tests/integration/Durable/` qui ne dépendent d'aucune infrastructure (étage milieu). Ces 6 classes sont tout le milieu déclaré de la pyramide pour un cœur de ~16 300 lignes plus un bundle de ~3 600 ; le seul harnais de reprise après panne du dépôt, `tests/integration/Durable/Support/StepwiseWorkflowHarness.php`, n'est utilisé que par `WorkflowSignalUpdateMessengerHandlersTest`.
- **Amont** : la documentation de test de Symfony sépare explicitement les *unit tests*, les *integration tests* (« test the interaction of multiple objects », avec conteneur mais sans requête HTTP) et les *application tests*, et fait de cette séparation la base du découpage des suites. <https://symfony.com/doc/current/testing.html>
- **Correctif** : scinder en deux testsuites — `integration` (`tests/integration/Durable`, toujours verte, jouée par le job `qa`) et `e2e` (`tests/integration/Temporal`, jouée par `temporal-integration`) — puis faire monter d'un cran les scénarios de reprise qui n'ont besoin que du harnais.

### C4 — Behat est installé et configuré à la ligne près, mais n'exerce aucun scénario du projet et n'est lancé par aucun job

- **Fichier** : `sylius/behat.yml.dist:1` ; `sylius/features/` (contient un unique `.gitignore` vide, 0 scénario)
- **Gravité** : mineur
- **Constat** : `sylius/composer.json:45-66` embarque douze dépendances Behat/Mink/Panther, et `behat.yml.dist` importe `vendor/sylius/sylius/src/Sylius/Behat/Resources/config/suites.yml` puis déclare `features` en second chemin de `SuiteSettingsExtension` — chemin vide. Aucune suite propre à Durable n'est déclarée, aucun contexte projet n'existe, et aucun job de `.github/workflows/ci.yml` n'invoque `vendor/bin/behat`. Le tableau de bord n'est pas pour autant sans couverture : `sylius/tests/Functional/DurableDashboardTest.php` en donne cinq cas HTTP réels (rendu, redirection anonyme, run en échec listé, historique du run sélectionné, nom du backend) et `ci.yml:220` les joue contre un vrai MySQL. La configuration Behat, elle, est du poids mort qui laisse croire à une couverture BDD inexistante.
- **Amont** : Behat n'hérite rien du profil par défaut — chaque suite doit déclarer ses propres contextes et chemins (<https://docs.behat.org/en/latest/user_guide/context.html>) ; le squelette de plugin Sylius livre pour cette raison un `features/` peuplé et ses contextes (<https://github.com/Sylius/PluginSkeleton>).
- **Correctif** : soit déclarer une suite `durable` nommant son contexte et `features/`, et l'ajouter au job `sylius-shop`, soit supprimer `behat.yml.dist` et les douze dépendances tant qu'aucun scénario ne les justifie.

### C5 — Assertion d'horloge murale dans la suite unitaire

- **Fichier** : `tests/unit/Durable/DriverParityRegressionTest.php:143`
- **Gravité** : mineur
- **Constat** : `self::assertLessThan(1.0, microtime(true) - $startedAt, '25 heures de sommeil ne doivent coûter aucun temps réel')` fait dépendre un test de la base de la pyramide de la charge de la machine. L'intention — prouver que le saut d'horloge n'attend pas — est légitime, mais la propriété testée est observable sans horloge : le journal contient les `TimerScheduled`/`TimerFired` correspondants et le harnais sait dire qu'aucun sommeil réel n'a eu lieu.
- **Amont** : non sourcé (règle interne DUR010, « Base — fast, reliable »).
- **Correctif** : remplacer l'assertion de durée par une assertion sur les événements de minuteur du journal, ou porter le budget à un ordre de grandeur (10 s) si l'on tient au garde-fou temporel.

### C6 — Attente fixe `sleep(4)` dans un cas d'intégration, là où le reste de la suite sonde

- **Fichier** : `tests/integration/Temporal/WorkflowFailurePathsTest.php:53`
- **Gravité** : mineur
- **Constat** : le cas affirme une **absence** (aucun événement terminal) après un `sleep(4)` en dur, alors que le reste de la suite d'intégration utilise partout des boucles de sondage conditionnelles (`usleep(250_000)` / `usleep(500_000)` sous `waitForHistoryEvent`), qui sont le bon motif. Une attente fixe pour une assertion négative est le cas le plus fragile qui soit : elle ne se durcit pas sous charge, elle ne fait que rendre le faux positif plus probable quand le serveur est lent à retenter. Le reste de l'isolation est correct : `TemporalServerTestCase:56` génère une file de tâches par test avec `random_bytes`, et le garde `markTestSkipped` sur `DURABLE_TEMPORAL_ADDRESS` est appliqué aux 19 classes.
- **Amont** : non sourcé.
- **Correctif** : remplacer par une attente bornée qui sonde l'historique et exige un nombre minimal de `ACTIVITY_TASK_FAILED` avant d'affirmer l'absence d'issue terminale — le fait à prouver est « il retente encore », pas « quatre secondes ont passé ».
