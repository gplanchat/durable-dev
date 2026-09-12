# nexus-operations Specification

## MODIFIED Requirements

### Requirement: A fulfilling workflow's parameter names are the contract's

An application SHALL be refused at registration when a workflow claiming a Nexus operation declares
a required parameter that matches no parameter of the operation it fulfils, **whatever host declares
it** — an attribute read by a compile pass, or a list read from a configuration file.

The payload is keyed by parameter name at both ends. Without the refusal the parameter receives
`null`: the workflow starts, runs, and returns a result computed on nothing.

#### Scenario: A required parameter that matches nothing is refused

- **WHEN** a workflow claiming an operation declares a required parameter absent from the
  operation's signature
- **THEN** registration fails
- **AND** the message names the workflow, the operation, and both parameter lists

#### Scenario: An optional extra parameter is allowed

- **WHEN** the extra parameter has a default value
- **THEN** registration succeeds, because its absence is a decision rather than an omission

#### Scenario: The refusal names what the reader must edit

- **WHEN** the host declares handlers through a configuration key rather than a compile pass
- **THEN** the message names that key
