# nexus-operations Specification

## ADDED Requirements

### Requirement: Serving is host work, and it is not Symfony work

An application SHALL serve a Nexus operation from a host that offers no attribute autoconfiguration
and no compile pass, declaring its handlers and its fulfilling workflows in ordinary configuration.

Everything served so far was registered by a Symfony compile pass and polled by a Symfony transport.
That proves a host can serve; it does not separate serving from that host.

#### Scenario: A handler declared in configuration answers on the task

- **WHEN** an application names a handler class and the contract it serves in its configuration
- **AND** a caller in another namespace invokes an operation of that contract
- **THEN** the handler answers on the task
- **AND** nothing about the operation is declared on the handler class itself

#### Scenario: A workflow fulfils an operation on the same host

- **WHEN** the contract declares an operation the handler class has no method for
- **AND** a workflow of that application claims the operation
- **THEN** the caller receives that workflow's result when it completes

#### Scenario: A fulfilling workflow calls an operation of its own

- **WHEN** the workflow fulfilling an operation calls an operation served by a third application
- **THEN** its execution records both the operation it serves and the operation it calls
- **AND** the original caller receives the result of the fulfilling workflow, unchanged by the
  intermediate call
