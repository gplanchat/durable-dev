# HIVE039 - Cursor-Based Pagination for Scalability

## Status
Active

## Context

During the fourth round of Payment Repository architectural review, cursor-based pagination was identified as a critical strategic improvement for handling large datasets. While the current offset-based pagination (HIVE037) works well for small to medium datasets, it suffers from performance degradation and consistency issues with large datasets.

Cursor-based pagination provides consistent performance regardless of dataset size and eliminates the "phantom read" problem where results can shift between pages due to concurrent modifications.

### Problem Statement

Offset-based pagination faces several limitations with large datasets:
- **Performance Degradation**: `OFFSET` becomes increasingly slow with large offsets (O(n) complexity)
- **Phantom Reads**: Results can shift between pages due to concurrent inserts/deletes
- **Memory Usage**: Database must process and skip all offset records
- **Inconsistent Results**: Users may see duplicate or missing records during pagination
- **Resource Consumption**: High CPU and I/O usage for deep pagination

### Evidence from Performance Analysis

**Offset-Based Pagination Performance:**
```sql
-- Page 1: Fast (0.1ms)
SELECT * FROM payments ORDER BY created_at DESC LIMIT 25 OFFSET 0;

-- Page 100: Slow (50ms)
SELECT * FROM payments ORDER BY created_at DESC LIMIT 25 OFFSET 2500;

-- Page 1000: Very Slow (500ms)
SELECT * FROM payments ORDER BY created_at DESC LIMIT 25 OFFSET 25000;
```

**Cursor-Based Pagination Performance:**
```sql
-- All pages: Consistent performance (0.1ms)
SELECT * FROM payments 
WHERE (created_at, uuid) < ('2024-01-15 10:30:00', 'uuid-value')
ORDER BY created_at DESC, uuid DESC 
LIMIT 25;
```

## Decision

We adopt **Cursor-Based Pagination** as the recommended approach for paginating large datasets (>10,000 records) across all bounded contexts, while maintaining offset-based pagination for smaller datasets.

### Core Principles

1. **Performance Consistency**: Pagination performance remains constant regardless of position
2. **Data Consistency**: Eliminates phantom reads and duplicate results
3. **Scalability**: Handles datasets of any size efficiently
4. **Backward Compatibility**: Coexists with existing offset-based pagination
5. **Index Optimization**: Leverages database indexes for optimal performance
6. **Deterministic Ordering**: Uses compound cursors for consistent results

### Cursor Implementation Pattern

```php
final readonly class Cursor
{
    public function __construct(
        public array $values,
        public array $columns,
        public string $direction = 'DESC'
    ) {}

    public static function fromString(string $cursorString): self
    {
        $decoded = base64_decode($cursorString);
        $data = json_decode($decoded, true);

        if (!$data || !isset($data['values'], $data['columns'])) {
            throw new InvalidCursorException('Invalid cursor format');
        }

        return new self(
            $data['values'],
            $data['columns'],
            $data['direction'] ?? 'DESC'
        );
    }

    public function toString(): string
    {
        $data = [
            'values' => $this->values,
            'columns' => $this->columns,
            'direction' => $this->direction
        ];

        return base64_encode(json_encode($data));
    }

    public function buildWhereClause(): string
    {
        $conditions = [];
        $operator = $this->direction === 'DESC' ? '<' : '>';

        // Build compound comparison for deterministic ordering
        for ($i = 0; $i < count($this->columns); $i++) {
            $columnConditions = [];

            // Equal conditions for previous columns
            for ($j = 0; $j < $i; $j++) {
                $columnConditions[] = "{$this->columns[$j]} = :{$this->columns[$j]}";
            }

            // Comparison condition for current column
            $columnConditions[] = "{$this->columns[$i]} {$operator} :{$this->columns[$i]}";

            $conditions[] = '(' . implode(' AND ', $columnConditions) . ')';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    public function getParameters(): array
    {
        $parameters = [];

        foreach ($this->columns as $index => $column) {
            $parameters[":{$column}"] = $this->values[$index];
        }

        return $parameters;
    }
}
```

### Cursor-Based Pagination Service

```php
final readonly class CursorPaginationService
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private PaginationValidator $validator,
    ) {}

    public function paginateWithCursor(
        string $baseQuery,
        array $baseParameters,
        array $orderColumns,
        ?Cursor $cursor = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        string $direction = 'DESC'
    ): CursorPaginatedResult {
        // Validate page size
        $validation = $this->validator->validatePaginationParameters(1, $pageSize);
        $validatedSize = $validation['page_size'];

        // Build query with cursor conditions
        $query = $this->buildCursorQuery($baseQuery, $orderColumns, $cursor, $direction);
        $parameters = $baseParameters;

        // Add cursor parameters if present
        if ($cursor !== null) {
            $parameters = array_merge($parameters, $cursor->getParameters());
        }

        // Add limit parameter
        $query .= ' LIMIT :limit';
        $parameters[':limit'] = $validatedSize + 1; // Fetch one extra to determine if there's a next page

        // Execute query
        $statement = $this->connection->prepare($query);
        foreach ($parameters as $param => $value) {
            $statement->bindValue($param, $value, $this->getParameterType($value));
        }

        $result = $statement->executeQuery();
        $items = $result->fetchAllAssociative();

        // Determine if there's a next page
        $hasNextPage = count($items) > $validatedSize;
        if ($hasNextPage) {
            array_pop($items); // Remove the extra item
        }

        // Create next cursor if there are more items
        $nextCursor = null;
        if ($hasNextPage && !empty($items)) {
            $lastItem = end($items);
            $cursorValues = array_map(fn($column) => $lastItem[$column], $orderColumns);
            $nextCursor = new Cursor($cursorValues, $orderColumns, $direction);
        }

        return new CursorPaginatedResult(
            $items,
            $validatedSize,
            $hasNextPage,
            $nextCursor,
            $cursor
        );
    }

    private function buildCursorQuery(
        string $baseQuery,
        array $orderColumns,
        ?Cursor $cursor,
        string $direction
    ): string {
        $query = $baseQuery;

        // Add cursor WHERE conditions
        if ($cursor !== null) {
            $whereClause = $cursor->buildWhereClause();

            // Check if base query already has WHERE clause
            if (stripos($query, 'WHERE') !== false) {
                $query .= " AND {$whereClause}";
            } else {
                $query .= " WHERE {$whereClause}";
            }
        }

        // Add ORDER BY clause
        $orderClauses = array_map(fn($column) => "{$column} {$direction}", $orderColumns);
        $query .= ' ORDER BY ' . implode(', ', $orderClauses);

        return $query;
    }

    private function getParameterType(mixed $value): int
    {
        return match (gettype($value)) {
            'integer' => ParameterType::INTEGER,
            'boolean' => ParameterType::BOOLEAN,
            'double' => ParameterType::STRING, // Handle as string for precision
            default => ParameterType::STRING,
        };
    }
}
```

### Domain-Specific Cursor Pagination

```php
final readonly class PaymentCursorPaginationService
{
    private const DEFAULT_ORDER_COLUMNS = ['creation_date', 'uuid'];
    private const ALLOWED_ORDER_COLUMNS = [
        'creation_date',
        'expiration_date',
        'completion_date',
        'uuid',
        'total',
        'status'
    ];

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private PaymentHydrator $hydrator,
        private CursorPaginationService $cursorPaginationService,
    ) {}

    public function paginatePaymentsWithCursor(
        RealmId $realmId,
        ?Cursor $cursor = null,
        int $pageSize = 25,
        ?OrganizationId $organizationId = null,
        array $orderColumns = self::DEFAULT_ORDER_COLUMNS,
        string $direction = 'DESC'
    ): PaymentCursorPage {
        // Validate order columns
        $this->validateOrderColumns($orderColumns);

        // Build base query
        $baseQuery = 'SELECT uuid, realm_id, organization_id, subscription_id, creation_date, expiration_date, completion_date, status, gateway, currency, subtotal, discount, taxes, total, captured FROM accounting_payments';

        $baseParameters = [':realm_id' => $realmId->toString()];
        $whereConditions = ['realm_id = :realm_id'];

        // Add organization filter if specified
        if ($organizationId !== null) {
            $whereConditions[] = 'organization_id = :organization_id';
            $baseParameters[':organization_id'] = $organizationId->toString();
        }

        $baseQuery .= ' WHERE ' . implode(' AND ', $whereConditions);

        // Execute cursor pagination
        $result = $this->cursorPaginationService->paginateWithCursor(
            $baseQuery,
            $baseParameters,
            $orderColumns,
            $cursor,
            $pageSize,
            $direction
        );

        // Hydrate results
        $payments = [];
        foreach ($result->items as $item) {
            $this->hydrator->assertRow($item);
            $payments[] = $this->hydrator->hydrateInstance($item);
        }

        return new PaymentCursorPage(
            $payments,
            $result->pageSize,
            $result->hasNextPage,
            $result->nextCursor,
            $result->currentCursor
        );
    }

    private function validateOrderColumns(array $orderColumns): void
    {
        foreach ($orderColumns as $column) {
            if (!in_array($column, self::ALLOWED_ORDER_COLUMNS, true)) {
                throw new InvalidArgumentException("Invalid order column: {$column}");
            }
        }

        // Ensure deterministic ordering by requiring a unique column
        if (!in_array('uuid', $orderColumns, true)) {
            throw new InvalidArgumentException('Order columns must include uuid for deterministic ordering');
        }
    }
}
```

### Cursor Pagination Result Objects

```php
final readonly class CursorPaginatedResult
{
    public function __construct(
        public array $items,
        public int $pageSize,
        public bool $hasNextPage,
        public ?Cursor $nextCursor,
        public ?Cursor $currentCursor,
    ) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function getMetadata(): array
    {
        return [
            'page_size' => $this->pageSize,
            'returned_items' => $this->count(),
            'has_next_page' => $this->hasNextPage,
            'next_cursor' => $this->nextCursor?->toString(),
            'current_cursor' => $this->currentCursor?->toString(),
        ];
    }
}

final readonly class PaymentCursorPage extends CursorPaginatedResult implements \IteratorAggregate
{
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}
```

### Repository Integration Pattern

```php
final readonly class DatabasePaymentRepository implements PaymentRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private PaymentHydrator $hydrator,
        private PaymentPaginationService $offsetPaginationService,
        private PaymentCursorPaginationService $cursorPaginationService,
        private DatabaseOperationLogger $operationLogger,
    ) {}

    // Existing offset-based pagination for small datasets
    public function list(RealmId $realmId, int $currentPage = 1, int $pageSize = 25): PaymentPage
    {
        return $this->offsetPaginationService->paginatePayments($realmId, $currentPage, $pageSize);
    }

    // New cursor-based pagination for large datasets
    public function listWithCursor(
        RealmId $realmId,
        ?string $cursor = null,
        int $pageSize = 25,
        ?OrganizationId $organizationId = null
    ): PaymentCursorPage {
        $startTime = microtime(true);

        $context = $this->operationLogger->createLoggingContext('list_payments_cursor', [
            'realm_id' => $realmId->toString(),
            'organization_id' => $organizationId?->toString(),
            'page_size' => $pageSize,
            'has_cursor' => $cursor !== null,
        ]);

        $this->operationLogger->logOperationStart('Starting cursor-based payment list retrieval', $context);

        try {
            $cursorObject = $cursor ? Cursor::fromString($cursor) : null;

            $paymentPage = $this->cursorPaginationService->paginatePaymentsWithCursor(
                $realmId,
                $cursorObject,
                $pageSize,
                $organizationId
            );

            $executionTime = microtime(true) - $startTime;

            $this->operationLogger->logCursorPaginationSuccess(
                'Cursor-based payment list retrieved successfully',
                $context,
                $executionTime,
                $paymentPage->count(),
                $paymentPage->hasNextPage
            );

            return $paymentPage;

        } catch (InvalidCursorException $exception) {
            $executionTime = microtime(true) - $startTime;
            $this->operationLogger->logValidationFailure('cursor validation', $context, [$exception->getMessage()]);
            throw new ValidationException('Invalid cursor provided', [$exception->getMessage()]);

        } catch (Exception $exception) {
            $executionTime = microtime(true) - $startTime;
            $this->handleDatabaseException($exception, $context, $executionTime, 'cursor-based payment list retrieval');
        }
    }
}
```

### API Integration Pattern

Following API Platform's cursor-based pagination patterns from https://api-platform.com/docs/core/pagination/#cursor-based-pagination

```php
// API Platform Resource with Cursor Pagination
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/payments',
            paginationEnabled: true,
            paginationType: 'cursor',
            paginationViaCursor: [
                ['field' => 'creation_date', 'direction' => 'DESC'],
                ['field' => 'uuid', 'direction' => 'ASC']
            ],
            paginationItemsPerPage: 25,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            provider: PaymentCursorProvider::class,
            openapiContext: [
                'parameters' => [
                    [
                        'name' => 'cursor',
                        'in' => 'query',
                        'description' => 'Cursor for pagination navigation',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'example' => 'eyJ2YWx1ZXMiOlsiMjAyNC0wMS0xNSAxMDozMDowMCIsInV1aWQtdmFsdWUiXSwiY29sdW1ucyI6WyJjcmVhdGlvbl9kYXRlIiwidXVpZCJdLCJkaXJlY3Rpb24iOiJERVNDIn0='
                    ],
                    [
                        'name' => 'itemsPerPage',
                        'in' => 'query',
                        'description' => 'Number of items per page',
                        'required' => false,
                        'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]
                    ]
                ]
            ]
        )
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['organization.id' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['creation_date', 'total', 'status'])]
class Payment
{
    // Payment entity properties...
}

// Custom Cursor Provider following API Platform patterns
final readonly class PaymentCursorProvider implements ProviderInterface
{
    public function __construct(
        private DatabasePaymentRepository $paymentRepository,
        private RequestStack $requestStack,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();

        // Extract realm from headers
        $realmId = RealmId::fromString($request->headers->get('X-Realm-ID'));

        // Get pagination parameters
        $cursor = $request->query->get('cursor');
        $itemsPerPage = (int) ($request->query->get('itemsPerPage', 25));

        // Get filters
        $organizationId = $request->query->get('organization.id') 
            ? OrganizationId::fromString($request->query->get('organization.id'))
            : null;

        // Use cursor-based pagination
        $result = $this->paymentRepository->listWithCursor($realmId, $cursor, $itemsPerPage, $organizationId);

        // Convert to API Platform pagination format
        return $this->createPaginatedCollection($result, $request);
    }

    public function supports(Operation $operation, array $uriVariables = [], array $context = []): bool
    {
        return $operation instanceof GetCollection && 
               $operation->getClass() === Payment::class;
    }

    private function createCursoredPaginator(PaymentCursorPage $result, Request $request): CursoredPaginator
    {
        $nextUrl = null;
        if ($result->hasNextPage && $result->nextCursor) {
            $nextUrl = $this->buildCursorUrl($request, $result->nextCursor->toString());
        }

        return new CursoredPaginator(
            $result->items,
            1, // Cursor pagination doesn't use page numbers
            $result->pageSize,
            $result->currentCursor?->toString(),
            $nextUrl
        );
    }

    private function buildCursorUrl(Request $request, string $cursor): string
    {
        $queryParams = $request->query->all();
        $queryParams['cursor'] = $cursor;

        return $request->getSchemeAndHttpHost() . 
               $request->getPathInfo() . 
               '?' . http_build_query($queryParams);
    }
}

// Extended Paginated Collection for Cursor Support
class PaginatedCollection implements \IteratorAggregate, \Countable
{
    private ?string $nextPage = null;
    private ?string $currentCursor = null;

    public function __construct(
        private array $items,
        private ?int $currentPage = null,
        private ?int $itemsPerPage = null,
        private ?int $totalItems = null,
    ) {}

    public function setNextPage(string $nextPage): void
    {
        $this->nextPage = $nextPage;
    }

    public function setCurrentCursor(string $cursor): void
    {
        $this->currentCursor = $cursor;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getMetadata(): array
    {
        return [
            'current_page' => $this->currentPage,
            'items_per_page' => $this->itemsPerPage,
            'total_items' => $this->totalItems,
            'returned_items' => $this->count(),
            'has_next_page' => $this->nextPage !== null,
            'next_page_url' => $this->nextPage,
            'current_cursor' => $this->currentCursor,
        ];
    }
}
```

### API Platform Configuration

```yaml
# config/packages/api_platform.yaml
api_platform:
    pagination:
        enabled: true
        cursor:
            enabled: true
            default_direction: 'DESC'
            default_fields: ['id']
        items_per_page: 25
        maximum_items_per_page: 100
        client_items_per_page: true
        page_parameter_name: 'page'
        items_per_page_parameter_name: 'itemsPerPage'
        cursor_parameter_name: 'cursor'
```

### OpenAPI Documentation Enhancement

```php
// Enhanced OpenAPI documentation for cursor pagination
#[ApiResource(
    operations: [
        new GetCollection(
            openapiContext: [
                'summary' => 'Retrieve payments with cursor-based pagination',
                'description' => 'Returns a paginated list of payments using cursor-based pagination for optimal performance with large datasets.',
                'parameters' => [
                    [
                        'name' => 'cursor',
                        'in' => 'query',
                        'description' => 'Opaque cursor for pagination. Use the cursor from the previous response to get the next page.',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'example' => 'eyJ2YWx1ZXMiOlsiMjAyNC0wMS0xNSIsInV1aWQiXSwiY29sdW1ucyI6WyJjcmVhdGlvbl9kYXRlIiwidXVpZCJdfQ=='
                    ],
                    [
                        'name' => 'itemsPerPage',
                        'in' => 'query',
                        'description' => 'Number of items to return per page (1-100)',
                        'required' => false,
                        'schema' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 100,
                            'default' => 25
                        ]
                    ],
                    [
                        'name' => 'organization.id',
                        'in' => 'query',
                        'description' => 'Filter payments by organization ID',
                        'required' => false,
                        'schema' => ['type' => 'string', 'format' => 'uuid']
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Paginated list of payments',
                        'content' => [
                            'application/ld+json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        '@context' => ['type' => 'string'],
                                        '@id' => ['type' => 'string'],
                                        '@type' => ['type' => 'string'],
                                        'hydra:member' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => '#/components/schemas/Payment']
                                        ],
                                        'hydra:totalItems' => ['type' => 'integer', 'nullable' => true],
                                        'hydra:view' => [
                                            'type' => 'object',
                                            'properties' => [
                                                '@id' => ['type' => 'string'],
                                                '@type' => ['type' => 'string'],
                                                'hydra:next' => ['type' => 'string', 'nullable' => true],
                                                'cursor' => ['type' => 'string', 'nullable' => true]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        )
    ]
)]
```

### Performance Optimization

#### Required Database Indexes

```sql
-- Compound index for cursor-based pagination
CREATE INDEX idx_payments_cursor_default ON accounting_payments(realm_id, creation_date DESC, uuid DESC);

-- Organization-specific cursor pagination
CREATE INDEX idx_payments_cursor_org ON accounting_payments(realm_id, organization_id, creation_date DESC, uuid DESC);

-- Alternative ordering indexes
CREATE INDEX idx_payments_cursor_total ON accounting_payments(realm_id, total DESC, uuid DESC);
CREATE INDEX idx_payments_cursor_status ON accounting_payments(realm_id, status, creation_date DESC, uuid DESC);
```

#### Query Performance Analysis

```php
final readonly class CursorPerformanceAnalyzer
{
    public function analyzeCursorQuery(string $query, array $parameters): array
    {
        $explainQuery = "EXPLAIN (ANALYZE, BUFFERS) {$query}";

        $statement = $this->connection->prepare($explainQuery);
        foreach ($parameters as $param => $value) {
            $statement->bindValue($param, $value);
        }

        $result = $statement->executeQuery();
        $explanation = $result->fetchAllAssociative();

        return [
            'execution_time' => $this->extractExecutionTime($explanation),
            'index_usage' => $this->extractIndexUsage($explanation),
            'rows_examined' => $this->extractRowsExamined($explanation),
            'performance_category' => $this->categorizePerformance($explanation),
        ];
    }
}
```

### Migration Strategy

```php
final readonly class PaginationMigrationService
{
    public function shouldUseCursorPagination(string $entityType, ?int $estimatedCount = null): bool
    {
        // Use cursor pagination for large datasets
        if ($estimatedCount !== null && $estimatedCount > 10000) {
            return true;
        }

        // Check configuration for entity-specific thresholds
        $threshold = $this->getEntityThreshold($entityType);

        if ($estimatedCount !== null) {
            return $estimatedCount > $threshold;
        }

        // Default to offset pagination for unknown sizes
        return false;
    }

    private function getEntityThreshold(string $entityType): int
    {
        return match ($entityType) {
            'payment' => 10000,
            'user' => 50000,
            'organization' => 5000,
            default => 10000,
        };
    }
}
```

## Consequences

### Positive

1. **Consistent Performance**: O(log n) complexity regardless of pagination depth
2. **Data Consistency**: Eliminates phantom reads and duplicate results
3. **Scalability**: Handles datasets of any size efficiently
4. **Resource Efficiency**: Lower CPU and memory usage compared to deep offset pagination
5. **Index Optimization**: Leverages database indexes effectively
6. **Real-time Friendly**: Works well with frequently updated datasets

### Negative

1. **Implementation Complexity**: More complex than offset-based pagination
2. **Limited Navigation**: Cannot jump to arbitrary pages
3. **Cursor Management**: Clients must manage cursor state
4. **Index Requirements**: Requires specific database indexes for optimal performance
5. **Backward Compatibility**: Different API contract from offset pagination

### Mitigation Strategies

- **Hybrid Approach**: Use offset pagination for small datasets, cursor for large ones
- **Client Libraries**: Provide client libraries to simplify cursor management
- **Index Monitoring**: Monitor index usage and performance
- **Documentation**: Comprehensive guides for cursor pagination usage

## Compliance

This ADR is **recommended** for:
- Datasets with >10,000 records
- Frequently updated datasets where consistency is critical
- APIs requiring high performance pagination
- Real-time applications with concurrent modifications

This ADR is **optional** for:
- Small datasets (<10,000 records)
- Admin interfaces where page jumping is required
- Legacy systems with existing offset pagination

## Related ADRs

- **HIVE037**: Pagination Implementation Guidelines - Foundation for offset-based pagination
- **HIVE036**: Input Validation Patterns - Cursor validation requirements
- **HIVE035**: Database Operation Logging - Logging for cursor pagination operations

## Implementation Checklist

When implementing cursor-based pagination:

- [ ] Create Cursor value object with encoding/decoding capabilities
- [ ] Implement CursorPaginationService with compound cursor support
- [ ] Create domain-specific cursor pagination services
- [ ] Add required database indexes for cursor columns
- [ ] Implement cursor validation and error handling
- [ ] Create cursor pagination result objects
- [ ] Update repository methods to support cursor pagination
- [ ] Add API endpoints with cursor parameter support
- [ ] Implement performance monitoring for cursor queries
- [ ] Create migration strategy for existing offset pagination
- [ ] Write comprehensive tests for cursor edge cases
- [ ] Document cursor pagination usage and limitations

## Examples

Successful implementations:
- **Payment Repository**: Cursor-based pagination for large payment datasets
- **Compound Cursors**: Using (creation_date, uuid) for deterministic ordering
- **Performance Optimization**: Consistent sub-millisecond performance regardless of position

This pattern should be considered for all high-volume datasets to ensure scalable pagination performance.
