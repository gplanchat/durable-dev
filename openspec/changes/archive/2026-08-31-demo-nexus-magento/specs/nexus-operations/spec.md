# nexus-operations Specification

## ADDED Requirements

### Requirement: Calling an operation asks nothing of the host

An application SHALL call a Nexus operation with no host-specific wiring beyond what it already
needs to run a workflow against the cluster. Serving an operation requires the host to register
handlers and poll a Nexus task queue; calling requires neither.

Everything proven so far ran between two applications built on the same framework, sharing its
container, its compile passes and its worker transport. That proves the wire between two
namespaces; it does not separate what Nexus asks of the framework from what it asks of the
application.

#### Scenario: A host with no handler registry calls an operation

- **WHEN** an application whose host offers no Nexus handler registration and polls no Nexus task
  queue calls an operation served elsewhere
- **THEN** the calling workflow receives the result
- **AND** the calling application registers nothing in the serving application's namespace

#### Scenario: Both response shapes reach the same caller

- **WHEN** one workflow calls an operation answered on the task and an operation fulfilled by a
  workflow, on two different endpoints
- **THEN** both results reach it through the same stub API
- **AND** nothing in the calling code distinguishes the two

#### Scenario: A caller needs no endpoint of its own

- **WHEN** an application only calls operations
- **THEN** no Nexus endpoint targets its namespace
