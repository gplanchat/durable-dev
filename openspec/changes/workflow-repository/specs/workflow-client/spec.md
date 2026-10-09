## ADDED Requirements

### Requirement: An application reaches a workflow through its repository

An application SHALL reach the executions of a workflow class through a repository of that class,
declared once and injected where it is needed. The repository SHALL give a stub for an execution
that has not started, and a stub for one that has.

The same declaration SHALL work on every host. On the hosts that resolve parameter attributes, an
application MAY instead receive the repository of a workflow class through an attribute on the
constructor parameter, without declaring a class. A host that cannot resolve parameter attributes
SHALL say so in its documentation.

A repository whose workflow class is not registered SHALL be refused when the application boots,
naming the repository and the workflow. A repository, declared or received through a parameter
attribute, whose workflow declares a signal, query or update method named `start`, `execute`,
`result`, `cancel`, `terminate` or `executionId`, compared without regard to case, SHALL be refused
when the application boots, naming the workflow class and the method.

#### Scenario: A declared repository on three hosts

- **WHEN** the same repository class is declared in a Symfony, a Laravel and a Magento application
  that register its workflow
- **THEN** each application injects it and reaches the workflow's executions through it

#### Scenario: A repository received through a parameter attribute

- **WHEN** a Symfony or a Laravel service asks, through a parameter attribute, for the repository of
  a registered workflow class
- **THEN** it receives a repository of that workflow class

#### Scenario: A repository for a workflow that is not registered

- **WHEN** an application declares a repository for a workflow class it does not register
- **THEN** the application fails to boot, naming the repository and the workflow class

#### Scenario: A workflow method that takes a name the stub keeps

- **WHEN** an application registers a repository for a workflow that declares a signal method
  named `cancel`
- **THEN** the application fails to boot, naming the workflow class and the method `cancel`

### Requirement: Starting an execution says whether it waits

A stub for an execution that has not started SHALL offer two ways to start it: one that returns
without waiting for the execution's result, one that returns the execution's result. A backend
that runs an execution in the caller's process MAY return from the first only once the execution
suspends or ends; it SHALL NOT wait for a suspended execution to resume. The workflow's input
SHALL be passed by the names of the entry method's parameters.

The execution SHALL be started under the identifier the application gives, or under a generated
one that the stub exposes. Starting an execution that has already started, or one the identifier
reuse policy refuses, SHALL fail the same way on every backend.

#### Scenario: Starting without waiting

- **WHEN** an application creates a stub under an identifier and starts it with the input of a
  workflow that waits for a signal
- **THEN** the call returns while the execution waits
- **AND** the execution is found in the run catalogue under that identifier

#### Scenario: Starting and waiting for the result

- **WHEN** an application starts an execution through the form that waits
- **THEN** the call returns the value the workflow returned

#### Scenario: Starting twice

- **WHEN** an application starts an execution under an identifier whose execution has already
  started
- **THEN** the start fails, naming the identifier, whatever the backend
- **AND** the execution already started keeps its workflow, its input and its progress

#### Scenario: Two starts at the same time

- **WHEN** two processes start an execution under the same identifier at the same time
- **THEN** exactly one execution runs under that identifier
- **AND** the other start fails as a start of an already started execution

### Requirement: A started execution is reached through the workflow's own declarations

A stub for a started execution SHALL send a signal, run a query or send an update when the
application calls the method the workflow declares for it, and SHALL return the query's answer or
the update's result. It SHALL wait for the execution's result, with an optional bound. It SHALL
request the execution's cancellation, which the workflow receives where it waits, and SHALL
terminate the execution without running more of its code.

Every one of these operations SHALL behave the same on every backend. A query SHALL NOT add
anything to the execution's history.

Reaching an execution that does not exist, or that belongs to another workflow class, SHALL fail at
once, naming the identifier.

#### Scenario: Signal, query, update, result on every backend

- **WHEN** an application signals a running execution, queries it, sends it an update and waits for
  its result, on the in-memory, DBAL, Illuminate and Temporal backends
- **THEN** the query's answer reflects the signal, the update's result is returned, and the result
  is the workflow's
- **AND** the four backends give the same answers

#### Scenario: Two identical updates at the same time

- **WHEN** two callers send the same update, with the same arguments, to one execution at the same
  time, and the workflow returns a different result to each
- **THEN** each caller receives the result of its own update

#### Scenario: An execution that continued as new

- **WHEN** an application holds a stub for an execution that has continued as new, and waits for
  its result or cancels it
- **THEN** the result is the one the last run of the chain returns, and the cancellation reaches
  the run that is current
- **AND** the outcome is the same on every backend

#### Scenario: A query leaves no trace

- **WHEN** an application queries a running execution
- **THEN** the execution's history holds no new event afterwards

#### Scenario: Cancelling lets the workflow compensate

- **WHEN** an application cancels an execution that waits after completing two steps with
  compensations
- **THEN** the workflow runs the compensations and the execution ends cancelled

#### Scenario: Terminating runs nothing more

- **WHEN** an application terminates a waiting execution
- **THEN** the execution ends terminated and no further workflow code runs

#### Scenario: Cancelling or terminating an execution that has ended

- **WHEN** an application cancels or terminates an execution that has already ended
- **THEN** the call fails, naming the identifier and saying that the execution has ended
- **AND** the execution's outcome is unchanged

#### Scenario: An unknown execution

- **WHEN** an application asks the repository for an execution that does not exist
- **THEN** the call fails at once, naming the identifier

#### Scenario: Waiting too long

- **WHEN** an application waits for the result of an execution with a bound, and the execution
  does not finish within it
- **THEN** the wait fails, naming the execution and the bound, and the execution keeps running

### Requirement: A start option is honoured or refused, never ignored

Every option an application passes when it starts an execution SHALL either take effect on the
backend in use, or make the start fail, naming every option the backend refuses and the backend.
No option SHALL be accepted and then ignored.

#### Scenario: An option the backend cannot honour

- **WHEN** an application starts an execution with an option the backend in use cannot honour
- **THEN** the start fails, naming the option and the backend
- **AND** no execution is started

#### Scenario: Several refused options

- **WHEN** an application starts an execution with two options the backend in use cannot honour
- **THEN** the start fails once, naming both options
