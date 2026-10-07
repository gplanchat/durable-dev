## ADDED Requirements

### Requirement: Stubs are supplied as arguments of the workflow method

A workflow method SHALL be able to receive each of the three stubs a workflow uses as an argument
that the engine supplies: an activity stub, a Nexus stub and a child workflow stub. An attribute
on the parameter SHALL name the contract or the workflow class the stub is built from, and MAY
carry the stub's options as constants.

A supplied argument SHALL NOT be part of the workflow's input. The code that starts the workflow,
a parent starting it as a child, and a Nexus operation fulfilled by it SHALL pass the input
parameters only.

Every mistake in a supplied argument SHALL be reported when the workflow is registered, naming the
workflow, the method and the parameter: a stub parameter without its attribute, an attribute on a
parameter of another type, a contract or class that does not exist or is not what the stub needs,
an option the engine would refuse.

The same workflow, declared the same way, SHALL receive the same arguments on every host.

An option declared on a supplied stub SHALL be honoured by the backend in use. When the backend
does not honour it, registering the workflow SHALL fail, naming the parameter, every such option
and the backend. The option SHALL NOT be recorded and then ignored.

#### Scenario: A workflow receives its three stubs

- **WHEN** a workflow method declares an input parameter, an activity stub, a Nexus stub and a
  child workflow stub, each marked with the attribute naming what it is built from
- **AND** the workflow is started with a value for the input parameter only
- **THEN** the workflow runs, and each stub schedules its activity, its operation and its child

#### Scenario: A parent passes only the child's input

- **WHEN** a parent starts, as a child, a workflow whose method receives supplied stubs
- **THEN** the parent passes the child's input parameters and nothing else
- **AND** the child receives its stubs from the engine

#### Scenario: A stub parameter without its attribute

- **WHEN** a workflow method declares a Nexus stub or a child workflow stub parameter with no
  attribute naming its contract or its class
- **THEN** registering the workflow fails, naming the parameter
- **AND** no execution of that workflow can start

#### Scenario: A child option the backend in use cannot honour

- **WHEN** a workflow declares a supplied child workflow stub with a task queue for the child
- **AND** the application runs on a backend that runs a child in the parent's process and cannot
  route it to a task queue
- **THEN** registering the workflow fails, naming the parameter, the task queue option and the
  backend
- **AND** no execution of that workflow can start

#### Scenario: A child option every backend honours

- **WHEN** a workflow declares a supplied child workflow stub whose parent close policy abandons
  the child
- **AND** the parent completes while the child is still running
- **THEN** the child keeps running, on every backend

#### Scenario: The same declaration on every host

- **WHEN** the same workflow class is registered on a Symfony, a Laravel and a Magento application
- **THEN** it receives the same supplied arguments on the three of them

### Requirement: A child's workflow id can be set on each call

A workflow SHALL be able to start a child under a workflow id it computes, through a stub it
received as an argument, without building a new stub by hand.

Setting the id SHALL affect the start it is used for only. The stub it was set on SHALL keep its
own options, so one stub can start several children under different ids.

A replay SHALL start no second child: the id is computed by workflow code from its input, and the
start is recorded like any other.

#### Scenario: Two children under two ids from one stub

- **WHEN** a workflow starts two children through the same supplied stub, setting a different id
  for each
- **THEN** two children run, each under the id set for it

#### Scenario: A replay starts nothing new

- **WHEN** a workflow that started a child under an id it computed is replayed
- **THEN** no second child is started

#### Scenario: An entry method that collides with the per-call setting

- **WHEN** a workflow class whose entry method has the name of the per-call id setting is used as
  a child through a supplied stub
- **THEN** registering the parent fails, naming the child class and the conflicting method
