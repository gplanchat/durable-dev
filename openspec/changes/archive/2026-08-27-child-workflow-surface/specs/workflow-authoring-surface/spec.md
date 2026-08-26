## Purpose

What a workflow author can call on the environment they receive, what the engine keeps to itself,
and what a test is allowed to do that production code is not.

## ADDED Requirements

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
