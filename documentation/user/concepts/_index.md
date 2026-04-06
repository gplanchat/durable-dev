---
title: Concepts
weight: 20
---

# Concepts

## Workflow

A **workflow** is orchestration logic that must run **deterministically** when replayed from history. It schedules **activities**, waits on **timers**, and reacts to **signals** and **updates** without performing non-deterministic work directly inside the workflow function—side effects belong in **activities**.

## Activity

An **activity** is where I/O and non-deterministic work happen: HTTP calls, database access, randomness, and anything that must not be re-executed blindly on replay. The workflow calls activities through an **`ActivityInvoker`** bound to an activity interface; the runtime delivers them according to retry policies.

For step-by-step authoring, see [Creating a workflow](../workflows/) and [Creating activities](../activities/).

## Event history and replay

The orchestrator stores an **event history** (journal). **Replay** re-executes workflow code against that history so the program state matches what was already decided. The component exposes an **EventStore** that reads history in pages so large runs do not load everything into memory at once.

## Backends

- **Temporal** — production orchestration with persisted history and workers (no official PHP SDK in this project; integration uses the approach documented for contributors).
- **In-Memory** — fast, deterministic tests and local runs without a cluster.

## Symfony Messenger

In Symfony applications, **Messenger** can carry workflow resumes, activity execution, and related messages. That is an application-side transport; it does not replace Temporal’s role as the source of workflow history when Temporal is the backend.

## Going deeper

For **API details**, class names, and extension points, rely on the code and the contributor-facing **ADRs** in the repository—not every ADR is duplicated in this user guide.
