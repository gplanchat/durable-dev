# workflow-authoring-surface Specification

## Purpose
What a workflow author can call on the environment they receive, what the engine keeps to itself,
and what a test is allowed to do that production code is not.
## Requirements
### Requirement: Activities are scheduled through a typed contract

Workflow code SHALL schedule an activity through a stub built from a contract, so that the activity
called, its arguments and its return type are checked before the workflow runs.

Workflow code SHALL NOT be able to schedule an activity by naming it as a string with a free-form
payload. That form remains inside the engine, where the stub uses it.

#### Scenario: Calling an activity through its contract

- **WHEN** a workflow builds a stub from an activity contract in its constructor
- **AND** its workflow method calls a method of that stub
- **THEN** the activity declared by that contract method is scheduled
- **AND** the call returns something the workflow can await

#### Scenario: Calling a method the contract does not declare

- **WHEN** a workflow calls a stub method that carries no activity declaration
- **THEN** the call fails immediately, naming the contract and the method
- **AND** no activity is scheduled

#### Scenario: Reaching for the string form

- **WHEN** workflow code tries to schedule an activity by name and payload on the environment
- **THEN** the code does not compile

### Requirement: Query handlers are declared, not registered

A workflow SHALL declare a query handler by marking a method, and the engine SHALL wire it.

Workflow code SHALL NOT be able to register a query handler, ask whether one exists, or invoke one,
on the environment it receives. Those three are the engine's, and a workflow reaching them would be
bypassing the declaration it is supposed to use.

#### Scenario: A declared query is answered

- **WHEN** a workflow marks a method as a query handler
- **AND** a query of that name arrives while the execution is running
- **THEN** the marked method answers it
- **AND** the workflow was never asked to register anything

#### Scenario: Reaching for the registration API

- **WHEN** workflow code tries to register, probe or invoke a query handler on its environment
- **THEN** the code does not compile

### Requirement: A test can run a workflow in its production shape

The test harness SHALL be able to run a workflow class: the environment reaching its constructor,
its business arguments reaching its workflow method, exactly as in production.

A test written this way SHALL observe the same behaviour as the same class running on a backend —
including its activity results, its failures, and what its journal records.

#### Scenario: Running a workflow class under test

- **WHEN** a test runs a workflow class with an activity double registered for the activity it calls
- **THEN** the workflow method receives its business arguments
- **AND** the double is called with the arguments the workflow passed
- **AND** the test observes the value the workflow returned

#### Scenario: The anonymous form stays available

- **WHEN** a test runs a workflow body given as a closure rather than a class
- **THEN** it runs, receiving the environment as its argument
- **AND** the documentation states that this is the harness's own shape, not the shape of a workflow

### Requirement: The surface carries no unreachable verb

Every method a workflow author can reach on the environment SHALL have a use a workflow can
actually make of it.

A method that resolves a value into an already-settled awaitable — adding nothing a workflow could
not write — SHALL NOT be part of the surface.

#### Scenario: Reaching for the resolved-value helper

- **WHEN** workflow code tries to wrap a plain value as an awaitable through the environment
- **THEN** the code does not compile

### Requirement: Child workflows are started through a typed stub

Workflow code SHALL start a child workflow through a stub resolved from the child's class, so that
the workflow type, the arguments it receives and the result it returns are checked before the
parent runs.

Workflow code SHALL NOT be able to start a child workflow by naming its type as a string. That form
remains inside the engine, where the stub uses it.

#### Scenario: Starting a child workflow through its class

- **WHEN** a workflow builds a stub from a child workflow class
- **AND** calls the child's entry method on that stub
- **THEN** a child execution of that workflow type is started with the arguments given
- **AND** the call returns something the parent can await

#### Scenario: Calling something other than the child's entry point

- **WHEN** a workflow calls a stub method that is not the child's entry method
- **THEN** the call fails immediately, naming the class and the expected method
- **AND** no child execution is started

#### Scenario: Reaching for the string form

- **WHEN** workflow code tries to start a child workflow by naming its type on the environment
- **THEN** the code does not compile

### Requirement: A stub call assembles, it does not wait

A call on any stub SHALL start the work and return something the caller awaits. It SHALL NOT wait
on the caller's behalf.

A call that waited could not be raced, counted towards a quorum, or bounded by a deadline — which
is the only reason to build a stub rather than call the primitive. This holds for every stub, so
that "a stub call is an awaitable" is a rule a reader can rely on rather than a property to check
one stub at a time.

#### Scenario: Racing two children against each other

- **WHEN** a workflow starts two children through stubs without awaiting either
- **AND** assembles them into a race
- **THEN** the race settles on the first child to settle
- **AND** the losing child is cancelled

#### Scenario: Starting a child without ever awaiting it

- **WHEN** a workflow starts a child through a stub and returns without awaiting it
- **THEN** the child execution was started
- **AND** the parent's own completion is not blocked by it

