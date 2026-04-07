# DUR006 — No official Temporal PHP SDK and no RoadRunner

## Status

Accepted

## Context

The Temporal ecosystem for PHP commonly ships an **official SDK** and uses **RoadRunner** as a worker. The Durable project aims for **controlled dependencies** and a **clean integration layer** (ports/adapters), without locking the architecture into those stacks.

## Decision

It is **forbidden** to introduce or keep, within the Durable component scope:

- the **official Temporal SDK for PHP** (client, worker, helpers shipped by Temporal for PHP, etc.);
- **RoadRunner** and any component **depending on** or **required by** RoadRunner.

Integrations with Temporal use **project-approved means**: calls to the Temporal server API (gRPC/HTTP per implementation choices), in-repo clients, or other adapters consistent with DUR001–DUR005.

### Relationship to other ADRs

- **Repositories** (DUR002) and **EventStore** (DUR001) do not use official SDK types as the public contract.
- **Workflows** and **activities** (DUR003, DUR004) are modelled by the component; the execution worker is not RoadRunner.

## Consequences

- Any Composer dependency or module that pulls these technologies must be rejected or replaced.
- Installation and operations guides must document the chosen runtime (not RoadRunner) when the implementation ships.
