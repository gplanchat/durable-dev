## MODIFIED Requirements

### Requirement: Scheduling a Nexus operation

A workflow SHALL be able to schedule an operation on a Nexus endpoint and await its result. The
call SHALL identify an endpoint, a service, an operation, and an input payload, and MAY carry
schedule-to-close, schedule-to-start and start-to-close bounds and Nexus headers.

Headers supplied by the caller SHALL reach the scheduled operation unchanged, and SHALL be
validated where they are written rather than rejected by the server.

#### Scenario: A header survives the round trip

- **WHEN** a workflow schedules an operation carrying a header
- **THEN** the recorded `NEXUS_OPERATION_SCHEDULED` carries the same header, unchanged
