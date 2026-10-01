## ADDED Requirements

### Requirement: Three diagnostic commands behave the same on every host

An application SHALL offer `durable:capabilities`, `durable:health` and `durable:doctor` on
Symfony (`bin/console`), Laravel (`artisan`) and Magento (`bin/magento`), whatever backend it is
configured with. For one configuration, the three hosts SHALL print the same findings, in the same
format, and SHALL exit with the same code.

#### Scenario: The same configuration on three hosts

- **WHEN** a Symfony, a Laravel and a Magento application configured with the same backend run
  `durable:doctor --format=json`
- **THEN** the three outputs hold the same findings
- **AND** the three commands exit with the same code

#### Scenario: Health on a backend without a cluster

- **WHEN** an application configured with a SQL journal runs `durable:health`
- **THEN** the command exists and checks that the database answers
- **AND** it reports no worker role, since none polls a cluster

### Requirement: The capabilities a backend supports are declared once

Each backend SHALL declare which capabilities it supports, and from which server version when it
depends on one. `durable:capabilities` SHALL print the declaration of the configured backend. The
capability matrix of the user documentation SHALL be generated from the declarations, and a
committed matrix that differs from the generated one SHALL fail the project's tests.

#### Scenario: Listing what the backend supports

- **WHEN** an operator runs `durable:capabilities` on an application backed by a SQL journal
- **THEN** each capability is listed as supported or not supported
- **AND** Nexus call and Nexus serve are listed as not supported

#### Scenario: A server older than a capability's minimum

- **WHEN** an operator runs `durable:capabilities` against a Temporal server older than the minimum
  version of a capability
- **THEN** that capability is listed as not supported by this server, with the server's version
  and the minimum

#### Scenario: Capabilities without a server

- **WHEN** an operator runs `durable:capabilities` and the Temporal server does not answer
- **THEN** the static matrix of the backend is printed
- **AND** each row that depends on the server is marked "not checked"

#### Scenario: The documentation matrix drifts

- **WHEN** a backend's declaration changes and the documentation matrix is not regenerated
- **THEN** the project's tests fail, naming the matrix

### Requirement: Health covers every service the configuration depends on

`durable:health` SHALL check that the configured backend answers and, on a backend with workers,
that each worker role polls its task queue. It SHALL name each service it checked and the result.

#### Scenario: A worker role stopped polling

- **WHEN** the activity role's task queue has had no poller for longer than the silence threshold
- **THEN** `durable:health` names the role and its task queue
- **AND** it exits with a non-zero code

#### Scenario: The backend does not answer

- **WHEN** the configured backend does not answer
- **THEN** `durable:health` names the backend and the error
- **AND** it exits with a non-zero code

### Requirement: The doctor reports every gap with a link to its explanation

`durable:doctor` SHALL run the checks of `durable:health` and `durable:capabilities`, then check
the registered search attributes, the Nexus endpoints the workflows name, the journal's connection
(DUR054), and the declared workflows against the backend's capabilities. It SHALL report every
finding, not only the first. Each finding SHALL carry a stable code, its subject, and the URL of the
documentation page that explains it.

A check that the command cannot perform SHALL be reported as not checked, never as passed. When the
server does not answer, every probe that needs it SHALL be marked not checked, and the doctor SHALL
report one error for the unreachable server.

The doctor SHALL read the workflow list the host has built, and SHALL NOT build it itself. When the
application's container is not compiled, the doctor SHALL fail with a message naming the command
that compiles it.

#### Scenario: A Nexus handler on a SQL journal

- **WHEN** an application backed by a SQL journal declares a Nexus service handler and runs
  `durable:doctor`
- **THEN** a finding names the handler class and the Nexus serve capability
- **AND** it links to the page on backend capabilities

#### Scenario: A Nexus call on a SQL journal

- **WHEN** an application backed by a SQL journal registers a workflow whose method receives Nexus
  operations through a parameter attribute, and runs `durable:doctor`
- **THEN** an error names the workflow class, the parameter and the Nexus call capability

#### Scenario: A search attribute is not registered

- **WHEN** Durable's search attributes are enabled and one of them is not registered on the
  namespace
- **THEN** `durable:doctor` names the attribute and the namespace
- **AND** the finding links to the page that shows how to register it

#### Scenario: The journal shares the application's connection

- **WHEN** the journal is on the application's default database connection
- **THEN** `durable:doctor` reports a warning naming the connection and DUR054

#### Scenario: The server does not answer

- **WHEN** an operator runs `durable:doctor` and the Temporal server does not answer
- **THEN** each probe that needs the server is marked "not checked"
- **AND** one error names the unreachable server
- **AND** the command exits with a non-zero code

#### Scenario: The container is not compiled

- **WHEN** an operator runs `durable:doctor` on a Magento application before `setup:di:compile`
- **THEN** the command fails with a message naming `bin/magento setup:di:compile`

#### Scenario: Several gaps at once

- **WHEN** a configuration has an unregistered search attribute and a stopped worker role
- **THEN** `durable:doctor` reports both

### Requirement: The output is readable by a person and by a CI job

The three commands SHALL print a table by default and JSON with `--format=json`. The JSON SHALL
carry a format version. Each finding SHALL be an error or a warning. The three commands SHALL share
one exit code contract: non-zero when an error is found, zero otherwise, warnings included. With
`--fail-on=warning`, a warning SHALL also give a non-zero exit code. A CI job can then stop a
deployment on it.

#### Scenario: Gating a deployment

- **WHEN** a CI job runs `durable:doctor --format=json` and an error is found
- **THEN** the command exits with a non-zero code
- **AND** the output parses as JSON listing the gap

#### Scenario: Warnings only

- **WHEN** `durable:doctor` finds warnings and no error
- **THEN** it exits with code zero
- **AND** with `--fail-on=warning`, the same run exits with a non-zero code

#### Scenario: A clean configuration

- **WHEN** `durable:doctor` finds nothing to report
- **THEN** it exits with code zero
