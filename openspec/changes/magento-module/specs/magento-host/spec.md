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

### Requirement: Work rides Magento's own queue

Workflow resumes and activity dispatches SHALL travel on Magento's `MessageQueue`, declared through
the host's own configuration, rather than on a second queue introduced beside it.

An operator SHALL supervise the Durable consumers with the commands already supervising every other
Magento consumer.

#### Scenario: The activity of a workflow reaches a consumer

- **WHEN** a workflow schedules an activity
- **THEN** the activity is published to the module's queue topic
- **AND** `bin/magento queue:consumers:start` on that consumer executes it
- **AND** its result reaches the workflow's journal

#### Scenario: A consumer that dies mid-order does not lose the order

- **WHEN** a consumer process is killed while an activity is running
- **AND** the consumer is restarted
- **THEN** the execution resumes from its journal
- **AND** an activity whose result was already recorded is not run a second time

### Requirement: One execution is replayed by one consumer at a time

Magento's queue does not serialise messages per execution, so two consumers CAN dequeue two resumes
of the same execution. The module SHALL prevent them from replaying it concurrently.

The lock SHALL be shared across consumer processes. A configuration whose lock provider is
process-local SHALL be refused at startup, not discovered when two journals have already forked.

#### Scenario: Two consumers, one journal

- **WHEN** two resumes of the same execution are dequeued by two consumer processes at once
- **THEN** one replays and the other waits
- **AND** the execution's journal records each scheduled step once

#### Scenario: A process-local lock is refused before it can cost anything

- **WHEN** the module is configured with a lock provider that is not shared across processes
- **THEN** startup fails naming the provider and what it must be replaced with

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
