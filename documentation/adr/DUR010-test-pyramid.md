# DUR010 — Test pyramid

## Status

Accepted

## Context

A **surplus** of slow or brittle tests (UI, network, heavy infrastructure) slows feedback and hides regressions. A balanced **test pyramid** puts most confidence in **many** **fast**, **reliable** tests at the base, and **few** **expensive** tests at the top.

## Decision

The Durable project adopts a **test pyramid** aligned with component layers:

### Base — unit tests (majority)

- **Target**: pure logic, value objects, state machine, serialization rules, I/O-free transforms.
- **Nature**: fast, isolated, no database or Temporal.
- **Goal**: cover **paths** and **invariants** (determinism, simulated replay) at low cost.

### Middle — integration tests

- **Target**: **ports** with the **In-Memory** backend (DUR005), repository + EventStore + activity stubs chains per component scope.
- **Nature**: slower than unit, but **without** mandatory external infrastructure.
- **Goal**: validate **wiring** and **contracts** between internal modules.

### Top — end-to-end or system tests (minority)

- **Target**: full scenarios with **real Temporal** (or a dedicated test environment) when needed to validate **network/protocol compatibility** and **critical paths** not covered by In-Memory.
- **Nature**: slowest and most environment-sensitive; **limited** in number.
- **Goal**: confidence in **real** integration, not duplication of all unit coverage.

### Principles

- **Do not** invert the pyramid: avoid a majority of slow E2E tests for business detail.
- Regressions found in E2E should **ideally** gain a test **lower** in the pyramid to avoid repetition.

### Relationship to DUR009

- **Writing rules** (determinism, doubles, PHPUnit) apply at every level; the pyramid sets **where** to place **relative** effort.

## Consequences

- CI pipelines may **split** jobs (fast unit, integration, optional or scheduled E2E).
- Component evolution must **preserve** the ability to test heavily via **In-Memory** so every change does not depend on the top layer.
