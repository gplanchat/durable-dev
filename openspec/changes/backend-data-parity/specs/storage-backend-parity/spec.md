# storage-backend-parity Specification

## ADDED Requirements

### Requirement: Every storage backend runs every conformance suite

A storage port is a promise that an application can change backend without changing its own code.
That promise is only worth what proves it. Every backend the project ships SHALL run the conformance
suite of every port it implements, and a port SHALL NOT be considered implemented by a backend that
runs no suite for it.

Coverage SHALL be readable as a whole, not inferred from the presence of test files: it SHALL be
possible to state, for each port and each backend, whether the suite runs. A backend that cannot run
a suite without an external server SHALL still declare the pairing, so that the gap reads as a
gated suite rather than as an absence.

Where a document or a docblock states that a backend runs a suite, and it does not, the statement is
a defect of the same kind as a failing test: it makes a reader stop looking.

#### Scenario: Coverage is stated for every pairing

- **WHEN** the conformance coverage of the project is read
- **THEN** every pairing of a storage port with a backend that implements it is listed
- **AND** each is marked as running, or as gated on an external dependency
- **AND** no pairing is absent from the list

#### Scenario: A backend claiming a port runs its suite

- **WHEN** a backend is documented as implementing a storage port
- **THEN** the conformance suite for that port runs against it

### Requirement: Conformance suites test what makes backends interchangeable

A conformance suite SHALL exercise the properties on which a caller relies when it swaps one backend
for another — identity, ordering, paging, and the meaning of an empty answer — and SHALL NOT be
satisfied by re-testing several implementations that share one storage shape.

A property SHALL be expressed as an observable outcome, never as a stored representation. Two
backends that satisfy a property by encoding entirely different things SHALL both pass, and a
backend that satisfies it by accident of shared implementation SHALL NOT thereby be evidence about a
backend that does not share it.

#### Scenario: A suite passes on backends with unrelated storage

- **WHEN** the same suite runs against a backend storing runs in a relational table and against a
  backend reading them from a remote cluster
- **THEN** both pass without the suite asserting on any stored representation

#### Scenario: Identity round-trips on every backend

- **WHEN** an execution is started, then found by the identifier it was started with
- **THEN** the run returned is the run that was started
- **AND** this holds on every backend the suite runs against

#### Scenario: Paging holds on every backend

- **WHEN** a catalogue is read page by page on any backend
- **THEN** every recorded run appears exactly once
- **AND** an exactly full final page offers no next cursor

### Requirement: A port a backend cannot honour is not silently narrowed

Where a backend cannot honour part of a port, that SHALL be visible in the port rather than absorbed
by an implementation. An implementation SHALL NOT satisfy a method it cannot perform by doing
nothing, and SHALL NOT return the value that means "nothing recorded" to mean "I could not answer".

An operation a backend genuinely cannot perform SHALL be refused in a way a caller can detect before
depending on it.

#### Scenario: An unsupported operation is refused, not silently skipped

- **WHEN** a caller invokes an operation on a backend that cannot perform it
- **THEN** the call is refused with a reason naming the backend and the operation
- **AND** the caller is not told that the operation succeeded

#### Scenario: "Nothing recorded" is not overloaded

- **WHEN** a backend cannot answer a read
- **THEN** it does not return the value that means the record exists and is empty
