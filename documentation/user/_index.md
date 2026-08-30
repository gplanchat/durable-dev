---
title: User guide
weight: 1
bookFlatSection: false
---

# User guide

How to think about Durable and how to use it. Start with [Why Durable](why/) if you are still
deciding; the [home page](/) makes the same case interactively.

| | |
|---|---|
| [Why Durable](why/) | the problem it solves, what it replaces — and when you do not need it |
| [Packages](packages/) | the library, the bundle, the Temporal driver — what to install and when |
| [Getting started](getting-started/) | installation, Symfony configuration, a first workflow, worker commands |
| [Concepts](concepts/) | workflows, activities, replay and backends in plain language |
| [Backends](backends/) | in-memory versus Temporal, and what each one supports |
| [Creating a workflow](workflows/) | `WorkflowEnvironment`, signals, queries, updates, child workflows |
| [Creating activities](activities/) | activity contracts, dependency injection, the typed stub |
| [Failures and retries](failures/) | what the journal records, and why an activity stopped retrying |
| [Cancellation](cancellation/) | raising cancellation inside the workflow so it can compensate |
| [Nexus operations](nexus/) | calling an operation another team serves — and serving one |
| [Options and value objects](options/) | retry limits, timeouts, cron schedules, search attributes |
| [Testing workflows](testing/) | unit tests with no server, and the suite that runs against a real one |
| [Configuration reference](configuration/) | every `durable.yaml` key |

Architecture decision records (**DUR**) and working agreements (**WA**) live in the repository for
contributors, under `documentation/adr/` and `documentation/wa/`.
