---
title: Durable user guide
weight: 1
---

# Durable

**Durable** is a PHP library for **durable execution**: long-running workflows that survive restarts, coordinated with **Temporal**, with a **cursor-based event history**, **activities** for side effects, and **replay** so the same workflow code stays deterministic.

This site is the **user guide**: how to think about the component and how to use it. It is **not** a copy of internal architecture records (ADRs) or team working agreements—those stay in the repository for contributors.

## Who this guide is for

- Application developers integrating durable workflows in PHP.
- Teams running **Temporal** (or the **In-Memory** backend for tests and local runs).

## Sections

- [Getting started](getting-started/) — environment, first steps, where to look next.
- [Concepts](concepts/) — workflows, activities, replay, and backends in plain language.
- [Creating a workflow](workflows/) — interface, `WorkflowEnvironment`, `WorkflowMethod`, signals, queries, updates.
- [Creating activities](activities/) — activity interfaces, `ActivityMethod`, dependency injection, `ActivityInvoker`.
- [Testing workflows](testing/) — `DurableTestCase`, `ActivitySpy`, `WorkflowTestEnvironment`, and `DurableBundleTestTrait`.

## Source and feedback

The **source** for this guide lives in the repository under `documentation/user/`. **Architecture decisions** (prefix **DUR**) and **working agreements** (**WA**) are maintained separately under `documentation/adr/` and `documentation/wa/` for contributors.

If something here is unclear or wrong, open an issue or a pull request on the project repository.
