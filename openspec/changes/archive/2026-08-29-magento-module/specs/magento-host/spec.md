# magento-host Specification

## ADDED Requirements

### Requirement: Workflows and activities are discoverable inside a Magento container

A Magento application SHALL be able to declare workflow and activity classes to the Durable runtime,
and the runtime SHALL resolve them by the same names it resolves them by on every other host.

Magento's container has no equivalent of Symfony's tag autoconfiguration, so declaration is
explicit. What SHALL NOT differ is the workflow itself: a class written for the Symfony bundle SHALL
run unmodified here, because everything below the ports is the same component.

#### Scenario: A workflow written once runs on Magento

- **WHEN** a class carrying `#[Workflow]` is declared to the module
- **AND** the same class already runs on the Symfony bundle
- **THEN** it runs on Magento without modification
- **AND** the runtime resolves it by the name its attribute declares

#### Scenario: An undeclared workflow fails at the moment of the mistake

- **WHEN** an execution is started for a workflow type the module was never told about
- **THEN** it fails naming the type and where types are declared
- **AND** it does not wait for a handler that will never be registered

### Requirement: Work is drained by processes an operator already supervises

Durable's workers SHALL be `bin/magento` commands, so that an operator supervises them with what
already supervises every other long-running Magento process, and SHALL NOT introduce a second queue
beside the host's.

They are **not** Magento queue consumers, and the reason is measured: a worker holds its task by
long poll, and Magento's retry timer never asks whether the first consumer has finished before
handing the same message to a second.

Nor does work travel on Magento's `MessageQueue`. On the only durable backend this host reaches,
activity dispatch is a Temporal command on a Temporal task queue, and a resume is a workflow task —
neither is a message the host could carry. A queue here would be the second queue this requirement
forbids.

Nothing follows from this about two consumers replaying one execution concurrently. That collision
needs two resumes of the same execution to be two queue messages, and here a resume is never a
message at all — so the capability carries no locking requirement, not because the hazard is
tolerated but because this host cannot reach it. ⚠ It returns the day a host-native journal does.

#### Scenario: A worker is supervised like any other Magento process

- **WHEN** an operator runs the Durable worker
- **THEN** it is a `bin/magento` command, bounded so a supervisor can restart it
- **AND** no queue, topic or consumer is declared beside Magento's own

#### Scenario: A consumer that dies mid-order does not lose the order

- **WHEN** a worker process is killed while an activity is running
- **AND** a worker is restarted
- **THEN** the execution resumes from its journal
- **AND** an activity whose result was already recorded is not run a second time

### Requirement: Only the backends Magento can actually reach are accepted

The module SHALL support the in-memory and Temporal backends. It SHALL refuse the SQL backends,
because Magento ships neither of the connection types they bind to: state lives in a Temporal
cluster, or it lives in one process.

The refusal SHALL happen at **installation**, through the package manager: the module SHALL declare
the SQL bridge packages as Composer conflicts, so a project cannot assemble the incoherent
combination in the first place. A refusal a process discovers at boot arrives after someone has
already installed the wrong thing; this one arrives before.

#### Scenario: A SQL bridge cannot be installed beside the module

- **WHEN** a project requires the module and a SQL bridge package together
- **THEN** the installation is refused, naming the packages that cannot coexist
- **AND** nothing is written to the project

#### Scenario: Temporal carries an execution across a restart

- **WHEN** an execution is running against a Temporal cluster
- **AND** every Magento process is restarted
- **THEN** the execution continues from its recorded history
