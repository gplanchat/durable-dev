# État d'API — pagination, modèle de lecture HTTP, exposer les runs en API

## Synthèse
Réponse à la question directrice : **non pour les opérations d'item, oui pour les collections moyennant un adaptateur mince**. Le port `WorkflowRunCatalogInterface` n'offre aucune lecture d'un run isolé — un `Get` sur `/workflow_runs/{id}` n'est pas implémentable sans dérouler toutes les pages — et le côté écriture rend `void`, si bien qu'un `ProcessorInterface` n'a pas d'IRI à mettre dans son `Location:`. La pagination, elle, existe et est bien faite (par clé, curseur opaque, `n+1` pour trancher la dernière page) : ce qui manque n'est pas la pagination mais un **total**, qu'aucun des deux backends ne sait donner, ce qui plafonne à `PartialPaginatorInterface` et interdit les liens `page` d'Hydra. Sur un axe le périmètre est sain et il faut le dire : **il n'y a pas de couplage au backend** — le port est propre, les faits sont des DTO `readonly`, l'opacité du curseur est contractuelle et une suite de conformité (`src/Durable/Testing/WorkflowRunCatalogConformanceTestCase.php`) l'atteste ; l'hypothèse « retours en tableaux bruts » est fausse au port et vraie seulement au modèle de vue `RunDashboard`.

## Constats

### C1 — Aucune lecture d'un run isolé : l'opération `Get` est inimplémentable
- **Fichier** : `src/Durable/Port/WorkflowRunCatalogInterface.php:34-57` ; `src/Durable/Observation/RunDashboard.php:145-154`
- **Gravité** : bloquant
- **Constat** : Le port n'expose que `listRuns()`, `readHistory()` et `checkHealth()`, et `readHistory()` prend une `WorkflowRunDescription` complète — pas un identifiant — parce que Temporal exige `groupId` + `runId`. `RunDashboard::pick()` ne cherche le run sélectionné que dans la page courante et retombe silencieusement sur `$runs[0]` quand il n'y est pas : le défaut existe déjà côté tableau de bord. Un provider d'item ne peut donc rien faire de `$uriVariables['runId']` sinon paginer jusqu'à le trouver, et les `@id` de la collection pointeraient vers une opération qui n'existe pas.
- **Amont** : `ApiPlatform\State\ProviderInterface::provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null` — https://github.com/api-platform/core/blob/main/src/State/ProviderInterface.php : l'identifiant arrive en `uriVariables` et le provider doit rendre l'objet.
- **Correctif** : Ajouter au port un `findRun(string $runId, ?string $groupId = null): ?WorkflowRunDescription` — `DescribeWorkflowExecution` côté Temporal, `SELECT … WHERE execution_id = ?` côté DBAL — et faire passer `RunDashboard::pick()` par ce chemin plutôt que par le balayage de page.

### C2 — Le côté écriture ne rend pas l'identité de la ressource, et n'en rend pas la même selon le backend
- **Fichier** : `src/Durable/Port/WorkflowResumeDispatcher.php:25` ; `src/Bridge/Temporal/Port/TemporalWorkflowResumeDispatcher.php:38-52` ; `src/Bridge/Temporal/WorkflowClient.php:53-63`
- **Gravité** : bloquant
- **Constat** : `dispatchNewWorkflowRun()` rend `void` et l'appelant invente lui-même l'identifiant (`(string) Uuid::v4()`, `symfony/src/Durable/DurableSampleWorkflowRunner.php:49`). Côté Temporal cet identifiant devient le **workflowId** — `startAsync()` le rend tel quel (`WorkflowClient.php:59-62`) et le run id assigné par le serveur est perdu — or le catalogue expose ce workflowId comme `groupId` et le run id comme `runId` (`TemporalWorkflowRunCatalog.php:146-160`). Côté DBAL, le même identifiant *est* le `runId`. Un processor qui répond `202 + Location` écrirait donc une IRI qui résout sur un backend et pas sur l'autre.
- **Amont** : `ApiPlatform\State\ProcessorInterface` ; `documentation/ost/OST003-php-ecosystem-integrations.md:87-101`, qui pose ce processor comme « la même classe sur les deux frameworks » et le premier paquet à écrire.
- **Correctif** : Faire rendre à `dispatchNewWorkflowRun()` un handle (`runId` + `groupId`) plutôt que `void`, et définir une fois pour toutes l'identité de ressource — `groupId ?? runId` — pour que l'IRI émise à l'écriture soit celle que `findRun()` (C1) accepte en lecture.

### C3 — Aucun total : `PaginatorInterface` est hors d'atteinte, `TraversablePaginator` inutilisable
- **Fichier** : `src/Durable/Observation/WorkflowRunPage.php:17-25` ; `src/Bridge/Dbal/Store/DbalWorkflowRunCatalog.php:64-77`
- **Gravité** : majeur
- **Constat** : `WorkflowRunPage` porte les runs et un `nextCursor`, et rien d'autre. Le backend DBAL tranche l'existence d'une suite en lisant `LIMIT $limit + 1` — un choix délibéré et correct, mais qui exclut de connaître le total ; `ListWorkflowExecutions` de Temporal n'en rend pas non plus. `hydra:totalItems` et le lien `last` sont donc structurellement impossibles.
- **Amont** : https://api-platform.com/docs/core/pagination — « If you are using custom state providers […] you will need to return an instance of `PartialPaginatorInterface` or `PaginatorInterface` » ; `ApiPlatform\State\Pagination\TraversablePaginator::__construct(\Traversable $traversable, float $currentPage, float $itemsPerPage, float $totalItems)` exige un total, et implémente `PaginatorInterface`, pas seulement la variante partielle.
- **Correctif** : Écrire à la main un `PartialPaginatorInterface` (une vingtaine de lignes : `IteratorAggregate` + `count()` sur `$page->runs`) au-dessus de `WorkflowRunPage`, et activer `paginationPartial: true` sur l'opération pour que l'absence de `last` soit un contrat annoncé et non une régression.

### C4 — Le curseur est opaque, `PartialPaginatorInterface` demande un numéro de page
- **Fichier** : `src/Durable/Observation/WorkflowRunPage.php:9-16` ; `src/Bridge/Temporal/Store/TemporalWorkflowRunCatalog.php:52-54,85`
- **Gravité** : majeur
- **Constat** : Le contrat impose que `nextCursor` soit opaque et rendu tel quel au même catalogue — jeton de page Temporal encodé en base64 d'un côté, `started_at`+`execution_id` de l'autre. Or `PartialPaginatorInterface::getCurrentPage(): float` est un **numéro** de page, et c'est lui qui alimente les liens `?page=N` d'Hydra : un provider ne peut pas les honorer, puisqu'il ne sait pas sauter à la page N.
- **Amont** : https://github.com/api-platform/core/blob/main/src/State/Pagination/PartialPaginatorInterface.php (`getCurrentPage(): float`, `getItemsPerPage(): float`) ; https://api-platform.com/docs/core/pagination — la pagination par curseur (`paginationViaCursor`) n'existe que pour Doctrine ORM/ODM et Elasticsearch, et exige un `RangeFilter` + un `OrderFilter` sur la propriété du curseur.
- **Correctif** : Ne pas passer par la pagination d'Hydra : déclarer `cursor` en paramètre de requête explicite sur l'opération, forcer `paginationPartial`, et émettre le lien `next` soi-même à partir de `$page->nextCursor`.

### C5 — Un seul filtre, et l'ordre est gelé par le curseur
- **Fichier** : `src/Durable/Port/WorkflowRunCatalogInterface.php:34` ; `src/Bridge/Dbal/Store/DbalWorkflowRunCatalog.php:55-68,132-134`
- **Gravité** : majeur
- **Constat** : `listRuns()` n'accepte qu'un `WorkflowRunStatus` : pas de nom de workflow, pas de fenêtre temporelle, pas de tri. Le curseur DBAL encode `started_at` et `execution_id` et la requête est figée en `ORDER BY started_at DESC, execution_id ASC` — un `OrderFilter` invaliderait tous les curseurs déjà émis. Un `SearchFilter`/`DateFilter` n'aurait donc rien où s'accrocher, et un provider ne pourrait qu'ignorer `$context['filters']`.
- **Amont** : `ApiPlatform\Doctrine\Orm\State\CollectionProvider` — https://github.com/api-platform/core/blob/main/src/Doctrine/Orm/State/CollectionProvider.php : les filtres n'y sont pas appliqués par le provider mais par les extensions sur le `QueryBuilder`, mécanisme dont un port non-Doctrine ne dispose pas et qu'il doit donc traduire lui-même.
- **Correctif** : Introduire un objet de critère (`workflowName`, intervalle, statut, tri) passé à `listRuns()` — plutôt qu'empiler des paramètres nullables — en documentant que le tri fait partie de la clé du curseur et qu'un curseur d'un autre tri est refusé.

### C6 — Le modèle de lecture existant est un contrat Twig, pas une ressource sérialisable
- **Fichier** : `src/Durable/Observation/RunDashboard.php:36-45,120-140`
- **Gravité** : majeur
- **Constat** : `build()` rend un `array{backend: array<string,mixed>, runs: list<array<string,mixed>>, kpis: …, pagination: …, selectedRun: …}` dont les clés `startedAt`, `endedAt`, `groupId` sont **conditionnellement absentes** — règle assumée et juste pour un gabarit, mais qui interdit un schéma OpenAPI stable et une hydratation client. La dégradation en tableaux est ici, pas au port : `WorkflowRunPage`, `WorkflowRunDescription` et `BackendHealth` sont des `final readonly class` correctes.
- **Amont** : https://api-platform.com/docs/guides/custom-pagination — le provider rend des objets de ressource, la normalisation est faite par le sérialiseur à partir de propriétés typées, pas d'un tableau à clés optionnelles.
- **Correctif** : Brancher le provider directement sur `WorkflowRunCatalogInterface` et rendre les `WorkflowRunDescription` (ou une ressource qui les enveloppe), en laissant `RunDashboard` à ce pour quoi il a été écrit — les gabarits Sylius et Magento.

### C7 — `details` est du vocabulaire de backend : pas de schéma décrivable
- **Fichier** : `src/Durable/Observation/WorkflowRunEvent.php:22-25,61`
- **Gravité** : mineur
- **Constat** : `$details` est un `array<string, mixed>` dont le docblock déclare explicitement que le contenu est celui du backend et non celui du composant, la normalisation étant remise à plus tard. Un gabarit s'en accommode ; un schéma OpenAPI ne peut le décrire que comme un objet libre, et un client d'API ne peut donc s'adosser à aucune clé.
- **Amont** : non sourcé (constat interne au dépôt ; c'est la conséquence directe du docblock, pas une règle amont).
- **Correctif** : Garder le brut mais le sortir du contrat : n'exposer comme propriétés typées que `sequence`, `recordedAt`, `kind`, `label`, `actionKey`, `started`, `failed`, et publier `details` en objet libre déclaré comme tel plutôt qu'en propriété promise.

### C8 — Une sonde de santé par requête de collection
- **Fichier** : `src/Durable/Observation/RunDashboard.php:62-80`
- **Gravité** : mineur
- **Constat** : `build()` appelle `checkHealth()` inconditionnellement avant toute liste, ce qui vaut un aller-retour gRPC ou SQL supplémentaire par appel. Le compromis est bon pour une page consultée à la main — mieux vaut une sonde qu'un tableau vide et serein — mais sur une collection HTTP appelée en boucle il double le trafic vers le backend.
- **Amont** : non sourcé (arbitrage de coût, pas une règle API Platform).
- **Correctif** : Exposer la santé comme sa propre ressource (`GET /durable/backend_health`, provider dédié) et ne pas la faire porter par le provider de collection.
