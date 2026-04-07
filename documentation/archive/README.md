# Architecture Decision Records (ADRs)

This directory contains Architecture Decision Records (ADRs) for the **Hive** project (files prefixed **`HIVE`**). ADRs document significant architectural decisions made during the development of the project across all bounded contexts including Accounting, Authentication, Cloud Management, Cloud Platform, Cloud Runtime, GenAI, and Platform.

**Durable component:** canonical decisions for the Durable PHP library live under **`documentation/adr/`** with the **`DUR`** prefix (see `documentation/INDEX.md`). Legacy Durable `ADR*.md` snapshots that were kept here for history have been **removed**; use the `DUR` series as the single source of truth.

## About ADRs

Architecture Decision Records are documents that capture important architectural decisions made during the project development. They provide context, rationale, and consequences of each decision, ensuring that the reasoning behind architectural choices is preserved and communicated effectively across the development team.

## ADR Management Process

For detailed information about how ADRs are managed in this project, including creation process, naming conventions, and lifecycle management, please refer to [HIVE000 - ADR Management Process](HIVE000-adr-management-process.md).

## Naming Convention

ADRs in this project follow the naming convention: `HIVE{number}-{short-title}.md`
- Numbers are zero-padded to 3 digits (e.g., HIVE001, HIVE042)
- Short titles use kebab-case (lowercase with hyphens)

## Current ADRs

### Process and Standards

| ADR                                                         | Title                                 | Description                                                                             |
|-------------------------------------------------------------|---------------------------------------|-----------------------------------------------------------------------------------------|
| [HIVE000](HIVE000-adr-management-process.md)                | ADR Management Process                | Establishes the foundational guidelines for managing ADRs in the Hive project           |
| [HIVE001](HIVE001-coding-standards.md)                      | Coding Standards                      | Defines PHP-CS-Fixer rules and coding standards for the entire codebase                 |
| [HIVE024](HIVE024-php-enum-naming-conventions.md)           | PHP Enum Naming Conventions           | Defines naming conventions for PHP enums in the Hive project                            |
| [HIVE027](HIVE027-phpunit-testing-standards.md)             | PHPUnit Testing Standards             | Defines mandatory standards for writing PHPUnit tests with minimal mocking              |
| [HIVE028](HIVE028-testing-data-and-faker-best-practices.md) | Testing Data and Faker Best Practices | Establishes mandatory standards for generating test data using the Faker library        |
| [HIVE029](HIVE029-dry-principle.md)                         | DRY Principle Application             | Defines guidelines for applying the DRY principle with specific 4+ occurrence threshold |

### Core Architecture

| ADR                                                                 | Title                                         | Description                                                                                           | Status                                 |
|---------------------------------------------------------------------|-----------------------------------------------|-------------------------------------------------------------------------------------------------------|----------------------------------------|
| [HIVE002](HIVE002-models.md)                                        | Models                                        | Describes the implementation of data models including Aggregates, Entities, Value Objects, and DTOs   | **Deprecated** (superseded by HIVE040) |
| [HIVE005](HIVE005-common-identifier-model-interfaces.md)            | Common Identifier Model Interfaces            | Defines basic model interfaces for identifiers used across all bounded contexts                                | Active                                 |
| [HIVE040](HIVE040-enhanced-models-with-property-access-patterns.md) | Enhanced Models with Property Access Patterns | Enhanced model implementation with explicit property access patterns, supersedes HIVE002              | Active                                 |
| [HIVE041](HIVE041-cross-cutting-concerns-architecture.md)           | Cross-Cutting Concerns Architecture           | Defines proper architectural placement of cross-cutting concerns and prohibited model implementations | Active                                 |

### Data Management

| ADR                                                        | Title                                                | Description                                                                                                                   |
|------------------------------------------------------------|------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| [HIVE003](HIVE003-dates.md)                                | Dates Management                                     | Establishes rules for handling date objects, timezones, and date formatting                                                   |
| [HIVE004](HIVE004-opaque-and-secret-data.md)               | Opaque and Secret Data Objects                       | Describes implementation of secured Value Objects for encryption and data protection                                          |
| [HIVE010](HIVE010-repositories.md)                         | Repositories                                         | Defines fundamental principles and responsibilities of repositories, including event bus integration for command repositories |
| [HIVE011](HIVE011-in-memory-repositories.md)               | In-Memory Repositories                               | Defines implementation patterns for in-memory repositories used in testing and development                                    |
| [HIVE012](HIVE012-database-repositories.md)                | Database Repositories                                | Describes implementation patterns for persistent database repositories using Doctrine DBAL                                    |
| [HIVE013](HIVE013-collection-management.md)                | Collection Management                                | Defines functional programming interfaces and patterns for working with datasets                                              |
| [HIVE014](HIVE014-elasticsearch-repositories.md)           | ElasticSearch Repositories                           | Describes implementation patterns for ElasticSearch repositories with full-text search and analytics capabilities             |
| [HIVE015](HIVE015-api-repositories.md)                     | API Repositories                                     | Defines implementation patterns for repositories that integrate with external APIs and third-party services                   |
| [HIVE016](HIVE016-database-migrations.md)                  | Database Migrations                                  | Defines how to use Doctrine DBAL migrations for database schema management across all bounded contexts                                 |
| [HIVE023](HIVE023-repository-testing-strategies.md)        | Repository Testing Strategies                        | Comprehensive testing strategies for all repository implementations ensuring proper test coverage and reliability             |
| [HIVE033](HIVE033-hydrator-implementation-patterns.md)     | Hydrator Implementation Patterns                     | Defines standardized patterns and guidelines for implementing hydrator classes that convert database rows to domain model objects   |
| [HIVE034](HIVE034-service-extraction-pattern.md)           | Service Extraction Pattern for Single Responsibility | Defines mandatory patterns for extracting services to maintain Single Responsibility Principle and eliminate code duplication |
| [HIVE035](HIVE035-database-operation-logging.md)           | Database Operation Logging Standardization           | Establishes standardized logging patterns for all database operations with consistent context and error classification        |
| [HIVE036](HIVE036-input-validation-patterns.md)            | Input Validation and Sanitization Patterns           | Defines comprehensive input validation patterns with graceful error handling and bounds checking                              |
| [HIVE037](HIVE037-pagination-implementation-guidelines.md) | Pagination Implementation Guidelines                 | Comprehensive guidelines for implementing consistent, reliable, and scalable pagination across all bounded contexts                    |
| [HIVE038](HIVE038-robust-error-handling-patterns.md)       | Robust Error Handling Patterns                       | Establishes systematic error classification, exception conversion, and recovery strategies for all operations                 |
| [HIVE039](HIVE039-cursor-based-pagination.md)              | Cursor-Based Pagination for Scalability              | Advanced pagination pattern for large datasets with consistent performance and data consistency                               |
| [HIVE048](HIVE048-in-memory-repository-storage-exceptions.md) | In-Memory Repository Storage Exceptions              | Defines exceptions to StorageMock requirement for primary storage repositories in testing                                    |
| [HIVE071](HIVE071-command-query-shared-storage-no-projection.md) | Command et Query – stockage partagé sans projection | Shared storage for Command/Query repos, no dedicated projector; In-Memory share StorageMock with Query model as stored representation |
| [HIVE049](HIVE049-amounts-and-currency.md)                 | Amounts and Currency Management                      | Standardizes the representation, serialization, and display of financial amounts across backend and frontend                    |
| [HIVE060](HIVE060-pdf-generation-accounting.md)            | PDF Generation for Accounting Bounded Context                 | Defines architecture for PDF generation, storage, and lifecycle management for invoices, credit memos, and refunds            |

### CQRS and API Platform

| ADR                                                   | Title                           | Description                                                                              |
|-------------------------------------------------------|---------------------------------|------------------------------------------------------------------------------------------|
| [HIVE006](HIVE006-query-models-for-api-platform.md)   | Query Models for API Platform   | Defines implementation of Query Models for reading data in CQRS architecture             |
| [HIVE007](HIVE007-command-models-for-api-platform.md) | Command Models for API Platform | Describes Command Models for state-changing operations in CQRS architecture              |
| [HIVE017](HIVE017-query-one-action-class.md)          | QueryOne Action Class           | Implementation of QueryOne action class for API Platform operations in CQRS architecture |
| [HIVE018](HIVE018-query-several-action-class.md)      | QuerySeveral Action Class       | Implementation of QuerySeveral action class for API Platform collection operations       |
| [HIVE019](HIVE019-create-action-class.md)             | Create Action Class             | Implementation of Create action class for API Platform entity creation operations        |
| [HIVE020](HIVE020-delete-action-class.md)             | Delete Action Class             | Implementation of Delete action class for API Platform entity removal operations         |
| [HIVE021](HIVE021-replace-action-class.md)            | Replace Action Class            | Implementation of Replace action class for API Platform complete entity replacement      |
| [HIVE022](HIVE022-apply-action-class.md)              | Apply Action Class              | Implementation of Apply action class for API Platform aggregate action operations        |

### Event-Driven Architecture

| ADR                                                           | Title                           | Description                                                                                                    |
|---------------------------------------------------------------|---------------------------------|----------------------------------------------------------------------------------------------------------------|
| [HIVE008](HIVE008-event-collaboration.md)                     | Event Collaboration             | Establishes Event-Driven Architecture and Event Collaboration patterns                                         |
| [HIVE009](HIVE009-message-buses.md)                           | Message Buses                   | Comprehensive guide for Event Bus, Command Bus, and Query Bus implementation                                   |
| [HIVE042](HIVE042-temporal-workflows-implementation.md)       | Temporal Workflows Implementation | Adoption and implementation of Temporal Workflows for orchestrating complex, long-running business processes |
| [HIVE050](HIVE050-event-publishing-responsibility.md)         | Event Publishing Responsibility | Defines that event publishing is the exclusive responsibility of Command Repositories, not Use Cases          |

### Security and Authorization

| ADR                                                          | Title                                  | Description                                                                                         |
|--------------------------------------------------------------|----------------------------------------|-----------------------------------------------------------------------------------------------------|
| [HIVE025](HIVE025-authorization-system.md)                   | Authorization System                   | Implementation of authorization system based on Symfony Security with action-based permissions      |
| [HIVE026](HIVE026-keycloak-resource-and-scope-management.md) | Keycloak Resource and Scope Management | Standardized approach for managing Keycloak resources and scopes with consistent naming conventions |
| [HIVE056](HIVE056-jwt-tokens-and-claims-architecture.md)     | JWT Tokens and Claims Architecture     | Architecture for managing JWT tokens and claims using JWS-based context with session isolation     |

### Frontend Architecture

| ADR                                                          | Title                                  | Description                                                                                         |
|--------------------------------------------------------------|----------------------------------------|-----------------------------------------------------------------------------------------------------|
| [HIVE045](HIVE045-public-pwa-architecture.md)               | Public PWA Architecture                | Bounded context-based architecture for the public PWA application using Chakra UI and DDD patterns          |
| [HIVE046](HIVE046-admin-pwa-architecture.md)                | Admin PWA Architecture                 | Bounded context-based architecture for the admin PWA application using React Admin and Material UI          |
| [HIVE055](HIVE055-context-mocking-pattern.md)               | Context Mocking Pattern                | Standardized pattern for mocking React contexts in tests and Storybook                              |

### GenAI Bounded Context

| ADR                                                          | Title                                  | Description                                                                                         |
|--------------------------------------------------------------|----------------------------------------|-----------------------------------------------------------------------------------------------------|
| [HIVE051](HIVE051-rag-implementation-genai.md)              | RAG Implementation for GenAI Bounded Context    | Architecture for implementing Retrieval-Augmented Generation system in the GenAI bounded context            |
| [HIVE052](HIVE052-mcp-server-implementation.md)             | MCP Server Implementation              | Architecture for implementing Model Context Protocol server to enable AI client integration       |

### Cloud Management Bounded Context

| ADR                                                          | Title                                  | Description                                                                                         |
|--------------------------------------------------------------|----------------------------------------|-----------------------------------------------------------------------------------------------------|
| [HIVE043](HIVE043-cloud-resource-sub-resource-architecture.md) | Cloud Resource Sub-Resource Architecture | Defines hierarchical resource relationships for Cloud Management, Platform, and Runtime bounded contexts    |
| [HIVE044](HIVE044-kubernetes-resource-labels-and-annotations.md) | Kubernetes Resource Labels and Annotations | Standardized patterns for Kubernetes resource labeling and annotation management                  |
| [HIVE054](HIVE054-cloud-resource-graph-architecture.md)     | Cloud Resource Graph Architecture      | Architecture for resource visualization and graph management in Cloud Management bounded context            |

### Testing Architecture

| ADR                                                          | Title                                  | Description                                                                                         |
|--------------------------------------------------------------|----------------------------------------|-----------------------------------------------------------------------------------------------------|
| [HIVE030](HIVE030-test-data-builder-pattern.md)             | Test Data Builder Pattern              | Defines mandatory patterns for creating test data builders when methods require 6+ parameters      |
| [HIVE031](HIVE031-circuit-breaker-pattern.md)               | Circuit Breaker Pattern                | Mandatory implementation of Circuit Breaker pattern for external service integrations              |
| [HIVE032](HIVE032-observability-strategies.md)              | Observability Strategies               | Establishes mandatory patterns for logging, metrics, and distributed tracing                       |
| [HIVE047](HIVE047-command-based-api-configuration.md)      | Command-Based API Configuration        | Defines how to configure API operations directly on Command and Query objects with specialized attributes |
| [HIVE053](HIVE053-ide-bounded-context-prospective-analysis.md) | IDE Bounded Context Prospective Analysis | Prospective analysis for IDE bounded context architecture                                          |
| [HIVE057](HIVE057-side-effect-bus.md)                      | Side Effect Bus                        | Architecture for managing side effects separately from command execution                            |
| [HIVE058](HIVE058-test-pyramid-architecture.md)             | Test Pyramid Architecture              | Defines the test pyramid structure and distribution for the Hive project                          |
| [HIVE059](HIVE059-test-data-fixtures-management.md)         | Test Data Fixtures Management          | Standardized approach for managing test data fixtures across the project                           |
| [HIVE061](HIVE061-jest-testing-standards.md)                | Jest Testing Standards                 | Defines mandatory standards for writing Jest tests in the PWA with minimal mocking (TypeScript/React equivalent of HIVE027) |
| [HIVE062](HIVE062-test-data-builder-pattern-pwa.md)         | Test Data Builder Pattern for PWA      | Defines mandatory patterns for creating test data builders in the PWA (TypeScript/React equivalent of HIVE030) |
| [HIVE063](HIVE063-test-data-fixtures-management-pwa.md)      | Test Data Fixtures Management for PWA  | Standardized approach for managing test data fixtures in the PWA (TypeScript/React equivalent of HIVE059) |

## ADR Dependencies

Several ADRs build upon or refine others:

- **HIVE040 (Enhanced Models with Property Access Patterns)** is the foundational ADR that many others reference (supersedes HIVE002)
- **HIVE041 (Cross-Cutting Concerns Architecture)** complements HIVE040 by defining proper placement of prohibited cross-cutting concerns
- **HIVE002 (Models)** is **deprecated** and superseded by HIVE040
- **HIVE003, HIVE004, HIVE005** are refinements of HIVE040 (originally HIVE002)
- **HIVE006, HIVE007** are refinements of HIVE040 (originally HIVE002) for CQRS implementation
- **HIVE008** is a refinement of HIVE040 (originally HIVE002) for event-driven architecture
- **HIVE009** builds upon HIVE040 (originally HIVE002), HIVE006, HIVE007, and HIVE008
- **HIVE010** is a refinement of HIVE040 (originally HIVE002) defining generic repository principles
- **HIVE011** is a refinement of HIVE040 (originally HIVE002), HIVE006, HIVE007, and HIVE010 for in-memory repositories
- **HIVE012** is a refinement of HIVE040 (originally HIVE002), HIVE006, HIVE007, HIVE010, and HIVE011 for database repositories
- **HIVE013** is a refinement of HIVE040 (originally HIVE002), HIVE006, HIVE010, HIVE011, and HIVE012 for collection management
- **HIVE014** is a refinement of HIVE040 (originally HIVE002), HIVE006, HIVE007, HIVE010, HIVE011, HIVE012, and HIVE013 for ElasticSearch repositories
- **HIVE015** is a refinement of HIVE040 (originally HIVE002), HIVE006, HIVE007, HIVE010, HIVE011, HIVE012, HIVE013, and HIVE014 for API repositories
- **HIVE016** is a refinement of HIVE040 (originally HIVE002) and HIVE012 for database schema management and migrations
- **HIVE017** is a refinement of HIVE006 for QueryOne action class implementation
- **HIVE018** is a refinement of HIVE006 for QuerySeveral action class implementation
- **HIVE019** is a refinement of HIVE007 for Create action class implementation
- **HIVE020** is a refinement of HIVE007 for Delete action class implementation
- **HIVE021** is a refinement of HIVE007 for Replace action class implementation
- **HIVE022** is a refinement of HIVE007 for Apply action class implementation
- **HIVE023** is an extension of HIVE010, HIVE011, HIVE012, HIVE014, and HIVE015 for repository testing strategies
- **HIVE024** is a refinement of HIVE001 for PHP enum naming conventions
- **HIVE025** establishes authorization system foundations for action-based permissions
- **HIVE026** is a refinement of HIVE025 for Keycloak resource and scope management standards
- **HIVE027** is a complement to HIVE023 extending testing guidelines to cover all types of PHPUnit tests
- **HIVE028** is a complement to HIVE023 and HIVE027 extending testing guidelines to cover test data generation practices
- **HIVE029** is a refinement of HIVE001 defining specific guidelines for DRY principle application in code
- **HIVE033** is a refinement of HIVE040 (originally HIVE002) and HIVE012 defining standardized patterns for hydrator implementations
- **HIVE034** is a refinement of HIVE040 (originally HIVE002), HIVE010, HIVE012, and HIVE029 defining service extraction patterns for Single Responsibility Principle
- **HIVE035** is a refinement of HIVE034 and HIVE012 establishing standardized database operation logging patterns
- **HIVE036** is a refinement of HIVE034 defining comprehensive input validation and sanitization patterns
- **HIVE037** is a refinement of HIVE034, HIVE035, HIVE036, and HIVE012 providing pagination implementation guidelines
- **HIVE038** is a refinement of HIVE034, HIVE035, and HIVE036 establishing robust error handling patterns
- **HIVE039** is a refinement of HIVE037 and HIVE036 providing advanced cursor-based pagination for scalability
- **HIVE042** is a complement to HIVE008 and HIVE009 establishing Temporal Workflows for complex business process orchestration
- **HIVE045** is a complement to HIVE040, HIVE006, HIVE007, HIVE008, HIVE009, and HIVE010 establishing frontend architecture patterns for the public PWA application
- **HIVE046** is a complement to HIVE045 establishing frontend architecture patterns for the admin PWA application with React Admin integration
- **HIVE047** is a refinement of HIVE006, HIVE007, HIVE017, HIVE018, HIVE019, HIVE020, HIVE021, and HIVE022 defining how to configure API operations directly on Command and Query objects
- **HIVE048** is an exception to HIVE011 defining when in-memory repositories may not use StorageMock
- **HIVE049** is a refinement of HIVE040 (originally HIVE002) and HIVE045 establishing standardized patterns for financial amount representation and currency handling across backend and frontend
- **HIVE050** is a refinement of HIVE010 and HIVE008 clarifying that event publishing is the responsibility of Command Repositories, not Use Cases
- **HIVE051** establishes RAG (Retrieval-Augmented Generation) architecture for the GenAI bounded context, building upon HIVE010, HIVE014, HIVE006, and HIVE007
- **HIVE052** establishes MCP (Model Context Protocol) server architecture for AI client integration, building upon HIVE051
- **HIVE053** is a prospective analysis for IDE bounded context architecture
- **HIVE054** is a refinement of HIVE043 establishing cloud resource graph architecture for visualization
- **HIVE055** is a complement to HIVE045 and HIVE046 establishing standardized patterns for mocking React contexts in tests
- **HIVE056** establishes JWT tokens and claims architecture using JWS-based context management, superseding any previous approaches that stored user context in Keycloak user attributes
- **HIVE057** is a refinement of HIVE009 establishing Side Effect Bus for managing side effects separately from commands
- **HIVE058** establishes test pyramid architecture defining test distribution and structure
- **HIVE059** establishes test data fixtures management patterns, complementing HIVE023, HIVE027, and HIVE028
- **HIVE061** is the TypeScript/React equivalent of HIVE027, defining Jest testing standards for the PWA, complementing HIVE058
- **HIVE062** is the TypeScript/React equivalent of HIVE030, defining Test Data Builder patterns for the PWA, complementing HIVE061
- **HIVE063** is the TypeScript/React equivalent of HIVE059, defining test data fixtures management for the PWA, complementing HIVE061, HIVE062, and HIVE058
- **HIVE060** establishes PDF generation architecture for Accounting bounded context, building upon HIVE004 and HIVE025

## Bounded Context Coverage

These ADRs provide architectural guidance for all Hive project bounded contexts:

- **Accounting**: Payment processing, subscription management, financial operations
- **Authentication**: User management, organization handling, security
- **Cloud Management**: Infrastructure management and orchestration
- **Cloud Platform**: Platform services and capabilities
- **Cloud Runtime**: Runtime environment and execution
- **GenAI**: Artificial intelligence and machine learning services
- **Platform**: Shared components and cross-cutting concerns

## Contributing

When creating new ADRs:

1. Follow the process outlined in [HIVE000](HIVE000-adr-management-process.md)
2. Use the established format and naming convention
3. Consider impact across all relevant bounded contexts
4. Reference related ADRs when appropriate
5. Update this README.md to include the new ADR

## Status Legend

- **Active**: Currently in effect and should be followed
- **Superseded**: Replaced by a newer ADR (reference provided)
- **Deprecated**: No longer recommended but not yet replaced

All ADRs listed above are currently **Active** unless otherwise noted.
