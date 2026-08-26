## Purpose

What a workflow author can call on the environment they receive, what the engine keeps to itself,
and what a test is allowed to do that production code is not.

## MODIFIED Requirements

### Requirement: Work is scheduled through a typed contract

Workflow code SHALL schedule an activity through a stub built from a contract, and SHALL start a
child workflow through a stub resolved from the child's class, so that what is called, its arguments
and its return type are checked before the workflow runs.

Workflow code SHALL NOT be able to schedule an activity or start a child workflow by naming it as a
string. Those forms remain inside the engine, where the stubs use them.

A stub call SHALL return something the workflow awaits, and SHALL NOT wait on the caller's behalf.
A call that waited could not be raced, counted towards a quorum, or bounded by a deadline — which
is the only reason to build the stub rather than call the primitive.

#### Scenario: Calling an activity through its contract

- **WHEN** a workflow builds a stub from an activity contract in its constructor
- **AND** its workflow method calls a method of that stub
- **THEN** the activity declared by that contract method is scheduled
- **AND** the call returns something the workflow can await

#### Scenario: Calling a method the contract does not declare

- **WHEN** a workflow calls a stub method that carries no activity declaration
- **THEN** the call fails immediately, naming the contract and the method
- **AND** no activity is scheduled

#### Scenario: Starting a child workflow through its class

- **WHEN** a workflow builds a stub from a child workflow class
- **AND** calls the child's entry method on that stub
- **THEN** a child execution of that workflow type is started with the arguments given
- **AND** the call returns something the workflow can await

#### Scenario: Calling something other than the child's entry point

- **WHEN** a workflow calls a stub method that is not the child's entry method
- **THEN** the call fails immediately, naming the class and the expected method
- **AND** no child execution is started

#### Scenario: Racing two children against each other

- **WHEN** a workflow starts two children through stubs without awaiting either
- **AND** assembles them into a race
- **THEN** the race settles on the first child to settle
- **AND** the losing child is cancelled

#### Scenario: Reaching for the string form

- **WHEN** workflow code tries to schedule an activity, or start a child workflow, by naming it as
  a string on the environment
- **THEN** the code does not compile
