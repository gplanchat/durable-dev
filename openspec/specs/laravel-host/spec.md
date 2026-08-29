# laravel-host Specification

## Purpose

Running Durable inside a Laravel application: how workflows and activities are declared to a
container that has no equivalent of Symfony's tag autoconfiguration, how their work rides the queue
the application already configures rather than a second one beside it, how two workers are kept from
replaying one execution at once, and how one configuration file binds every storage port to the same
backend.
## Requirements
### Requirement: Workflows and activities are declarable inside a Laravel application

A Laravel application SHALL be able to declare workflow and activity classes to the Durable runtime,
and the runtime SHALL resolve them by the same names it resolves them by on every other host.

Laravel's container has no equivalent of Symfony's tag autoconfiguration, so declaration is
explicit. What SHALL NOT differ is the workflow itself: a class written for the Symfony bundle SHALL
run unmodified here, because everything below the ports is the same component.

#### Scenario: A workflow written once runs on Laravel

- **WHEN** a class carrying `#[Workflow]` is declared to the application
- **AND** the same class already runs on the Symfony bundle
- **THEN** it runs on Laravel without modification
- **AND** the runtime resolves it by the name its attribute declares

#### Scenario: An undeclared workflow fails at the moment of the mistake

- **WHEN** an execution is started for a workflow type the application never declared
- **THEN** it fails naming the type and where types are declared
- **AND** it does not wait for a handler that will never be registered

### Requirement: Work rides the application's own queue

Workflow resumes and activity dispatches SHALL travel as jobs on the queue connection the
application already configures, rather than on a second queue introduced beside it.

An operator SHALL drain them with the command already draining every other job in the application.

#### Scenario: The activity of a workflow reaches a worker

- **WHEN** a workflow schedules an activity
- **THEN** the activity is dispatched as a job on the application's queue connection
- **AND** `php artisan queue:work` executes it
- **AND** its result reaches the workflow's journal

#### Scenario: A worker that dies mid-activity does not lose the execution

- **WHEN** a worker process is killed while an activity is running
- **AND** a worker is started again
- **THEN** the execution resumes from its journal
- **AND** an activity whose result was already recorded is not run a second time

#### Scenario: A timer does not hold a worker

- **WHEN** a workflow starts a timer
- **THEN** the resume is queued with the queue's own delay
- **AND** no worker is occupied while the timer runs

### Requirement: One execution is replayed by one worker at a time

Laravel's queue does not serialise jobs per execution, so two workers CAN dequeue two resumes of the
same execution. The package SHALL prevent them from replaying it concurrently.

The exclusion SHALL be shared across worker processes. A configuration whose lock store cannot lock
across processes SHALL be reported, and SHALL NOT be discovered by two journals that have already
forked.

#### Scenario: Two workers, one journal

- **WHEN** two resumes of the same execution are dequeued by two worker processes at once
- **THEN** one replays and the other does not
- **AND** the journal records one sequence of commands, not two interleaved ones
- **AND** the activities the execution schedules are dispatched once each

#### Scenario: A lock store that cannot lock across processes is not silently accepted

- **WHEN** the configured lock store is process-local
- **AND** more than one worker process is configured to drain the queue
- **THEN** the misconfiguration is reported
- **AND** it is reported before an execution has been replayed twice

### Requirement: The storage backend is chosen by configuration

The application SHALL choose its backend in one published configuration file, and the choice SHALL
bind every storage port together — a journal on one backend and metadata on another is not a
configuration, it is a fault.

#### Scenario: An application selects the SQL backend it already owns

- **WHEN** the published configuration selects the Illuminate backend
- **THEN** the journal, the workflow metadata, the parent links and the run catalog all resolve to
  it
- **AND** they resolve to the connection the application already configures
- **AND** a journal append and a business write made in the same transaction commit or roll back
  together

#### Scenario: A combination the package cannot serve is refused by name

- **WHEN** the configuration selects a backend this package does not bind
- **THEN** it fails naming the backend it was given and the backends it serves
- **AND** it fails at boot, not at the first execution
