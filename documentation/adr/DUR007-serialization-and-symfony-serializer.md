# DUR007 — Serialization and Symfony Serializer

## Status

Accepted

## Context

**Payloads** exchanged between workflows, activities, and the orchestrator (activity inputs/outputs, events relevant to the component, etc.) must be **serialized** in a **stable**, **interoperable** way. The component should not reinvent an ad hoc stack when a Symfony ecosystem standard fits.

## Decision

The Durable component’s **serialization** layer **uses Symfony’s Serializer component** (`symfony/serializer`) to:

- serialize and deserialize **activity** arguments and return values (DUR004);
- any other need for structured ↔ transportable representation (JSON or another format chosen by the backend) within the component scope.

### Principles

- Symfony **normalizers** and **encoders**: Serializer conventions (context, groups, types) to control formats and schema evolution.
- **Types**: types used in activity signatures and models exposed to serialization must be **compatible** with the pipeline (no resources, DTOs / value objects, typed collections, etc.) — specifics in implementation and tests.
- **Determinism**: the workflow stays deterministic (DUR003); serialization **does not** bypass the no-I/O rule in the workflow — it applies at activity / orchestrator boundaries and adapter layers.

### Coupling

- The Durable component may expose **factories** or **recommended** Serializer configuration (normalizers, encoders) for Symfony hosts.
- **Domain ports** do not depend on Serializer internals as the **public contract**: interfaces stay in the component’s PHP types; Symfony Serializer is a chosen **implementation mechanism**.

## Consequences

- The `symfony/serializer` dependency is expected in the component (or integrator bundle) Composer graph, in a version compatible with the supported PHP branch.
- Format evolution (new fields, versions) is handled via Serializer capabilities (groups, `@SerializedName`, etc.) and a documented compatibility policy with releases.
