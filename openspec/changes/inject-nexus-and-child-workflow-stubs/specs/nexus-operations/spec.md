## ADDED Requirements

### Requirement: The endpoint is named by the deployment, or by the call site

A Nexus endpoint says where a service is served, which changes from one environment to the next
while the contract does not. The endpoint a workflow calls SHALL therefore be resolvable from the
application's configuration, keyed by the contract, on every host.

An endpoint named where the stub is declared SHALL take precedence over the configuration. When
neither names one, the mistake SHALL be reported before any execution needs it: when the workflow
is registered for a stub the engine supplies, when the stub is built for a stub the workflow builds
itself. The report SHALL name the contract and the configuration key of the host in use.

Resolving an endpoint SHALL NOT change what is sent to the cluster: the operation is scheduled on
the resolved endpoint exactly as if the call site had named it.

#### Scenario: The endpoint comes from the configuration

- **WHEN** an application's configuration maps a Nexus contract to an endpoint
- **AND** a workflow calls an operation of that contract through a stub that names no endpoint
- **THEN** the operation is scheduled on the configured endpoint

#### Scenario: Two environments, two endpoints, one workflow

- **WHEN** the same workflow code runs in two environments whose configurations map the contract
  to two different endpoints
- **THEN** each environment schedules the operation on its own endpoint
- **AND** the workflow code is identical in both

#### Scenario: The call site overrides the configuration

- **WHEN** the configuration maps a contract to one endpoint
- **AND** the stub is declared with another endpoint
- **THEN** the operation is scheduled on the endpoint the stub declares

#### Scenario: No endpoint anywhere

- **WHEN** a workflow declares a supplied Nexus stub for a contract that neither the declaration
  nor the configuration maps to an endpoint
- **THEN** registering the workflow fails, naming the contract and the configuration key to set
