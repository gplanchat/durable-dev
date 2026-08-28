# nexus-operations Specification

## ADDED Requirements

### Requirement: Two applications, two namespaces, both roles

A Nexus operation SHALL work between two separate applications running as separate processes, in
separate Temporal namespaces, where each application both calls operations served by the other and
serves operations the other calls.

Everything proven so far ran a caller and a handler in one PHP process, with the tasks driven by
hand. That proves the wire; it does not prove the deployment.

#### Scenario: An immediate operation crosses the namespace boundary

- **WHEN** an application in one namespace calls an operation served by an application in another
- **AND** the serving application answers on the task itself
- **THEN** the calling workflow receives the result
- **AND** neither application is registered in the other's namespace

#### Scenario: A deferred operation crosses it too

- **WHEN** the served operation is fulfilled by a workflow in the serving application
- **THEN** the calling application holds nothing open while that workflow runs
- **AND** the caller receives the workflow's result when it completes

#### Scenario: Each application is on both ends

- **WHEN** application A calls an operation served by B
- **AND** B calls a different operation served by A
- **THEN** both directions resolve independently
