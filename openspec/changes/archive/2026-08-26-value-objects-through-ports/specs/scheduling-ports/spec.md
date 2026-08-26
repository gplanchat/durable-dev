## Purpose

What the core hands to a driver when it schedules work — activities, timers, child workflows — and
which side owns the translation into a backend's own primitives.

## ADDED Requirements

### Requirement: Scheduling options cross the port as value objects

The core SHALL pass scheduling options to a driver as the value objects that describe them, not as
arrays or bare numbers. A driver SHALL receive the same guarantees the caller constructed, without
having to reconstruct them.

#### Scenario: An activity is scheduled with its options intact

- **WHEN** a workflow schedules an activity with a retry limit, timeouts and a task queue
- **THEN** the driver receives those options as objects
- **AND** it does not parse them out of an untyped array

#### Scenario: A child workflow is scheduled with its options intact

- **WHEN** a workflow schedules a child with timeouts, a parent close policy and search attributes
- **THEN** the driver receives each of them typed
- **AND** no value reaches the driver as `mixed`

### Requirement: A timer is scheduled as a delay, not a deadline

The core SHALL express a timer as the **duration** to wait. Converting a delay into an absolute
instant is a backend concern: the in-memory driver needs a deadline to compare against its clock,
and the Temporal server requires a duration.

#### Scenario: The delay reaches the driver unconverted

- **WHEN** a workflow waits for a duration
- **THEN** the driver receives that duration
- **AND** it derives whatever representation its backend needs

#### Scenario: Replaying a scheduled timer does not reschedule it

- **WHEN** a workflow that already scheduled a timer is replayed
- **THEN** no new timer command is emitted
- **AND** the recorded timing is read back from history

### Requirement: Recorded timings are read back as durations

The history port SHALL express a recorded timing as a duration rather than a bare number, so a
value read from history and a value about to be written are the same kind of thing.

#### Scenario: A recorded timer is read back typed

- **WHEN** the engine reads a scheduled timer from history
- **THEN** the recorded timing is a duration
- **AND** it needs no unit convention to be interpreted

### Requirement: Serialisation belongs to the adapters

The value objects SHALL keep the ability to serialise themselves, because that is how the wire is
written. The core SHALL NOT invoke that ability to talk to a port; each driver SHALL invoke it when
it writes to its own storage or protocol.

#### Scenario: The in-memory driver serialises for the journal

- **WHEN** the in-memory driver records a scheduled activity
- **THEN** it writes the wire form of the options into the journal
- **AND** the recorded shape is byte-for-byte what it was before this change

#### Scenario: The Temporal driver translates to protobuf

- **WHEN** the Temporal driver builds a schedule command
- **THEN** it maps the options straight to protobuf fields
- **AND** it performs no intermediate array round trip

### Requirement: The wire format is unchanged

Journals and Temporal histories written before this change SHALL remain readable, and executions
in flight SHALL continue to replay. The change is internal to the boundary between core and
drivers.

#### Scenario: An execution started before the change still replays

- **WHEN** an execution whose history was written with the previous code is replayed
- **THEN** its activities, timers and child workflows resolve as they did before
- **AND** no migration step is required

#### Scenario: Recorded options round-trip unchanged

- **WHEN** options are recorded and read back
- **THEN** the stored keys and values are identical to those produced before this change
