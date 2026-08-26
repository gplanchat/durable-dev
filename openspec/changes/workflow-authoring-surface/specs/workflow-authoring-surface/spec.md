# workflow-authoring-surface Specification

## Purpose

What a workflow author can call on the environment they receive, what the engine keeps to itself,
and what a test is allowed to do that production code is not.

## ADDED Requirements

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
