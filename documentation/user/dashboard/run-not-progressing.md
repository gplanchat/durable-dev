---
title: A run does not progress
weight: 60
---

# A run does not progress

You opened the dashboard and a run stays where it was, or the list shows nothing. Each entry below
starts from what the page says. None of these states loses a step already recorded: the [journal](../../glossary/) (the recorded steps of an execution) keeps every completed step, and the run resumes at the first one missing.

## The line says `waiting for a worker · 42 s`

The run was dispatched and nothing has consumed it. The queue has no worker, or its worker is stopped. Start one:

- Symfony: [`durable:worker`](../../getting-started/#5-run-a-consumer-or-nothing-happens)
- Laravel: `php artisan queue:work`

The line disappears when a worker picks the run up. Sylius and Filament also count these runs under
**Waiting for a worker**.

## The run is running and carries no such line

A worker has picked it up. Read the `waiting on` line: a timer, an activity attempt or a condition
explains the wait, and a condition names where it is written, or the label given to `await()`. If
the wait is a Nexus operation, the operations table says whether it is in flight.

## The list is empty

The message above the list says which of three cases you are in.

| The page says | Cause | Action |
| --- | --- | --- |
| No readable backend is configured | No backend serves the dashboard | Configure one, see [Backends](../../backends/) |
| The backend cannot be reached | The named backend is down | Restart it |
| The journal dies with the request | The in-memory backend under PHP-FPM: the request that renders the page executed no workflow | Configure a backend shared across processes |

Magento has no banner for the first case: it reads the in-memory backend when nothing else is
configured.

## No worker is polling on Temporal

Nothing fails: an execution stops at its first task of that kind. On Sylius and Magento, the
dashboard names each role whose queue has no poller. From a shell, `bin/console durable:health`
exits non-zero when a role's queue has gone two minutes without a poll, and names the
`durable:worker --role` to start.

## `waiting for a worker` never appears

The backend records no pickup time. The SQL backends record it, on a runs table that has the `picked_up_at` column
(see [Upgrading](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md) for a table created
before it existed). Temporal records none the run list can read, and the Magento grid does not show it:
Temporal UI lists the pending tasks.

## The name and id filters are missing

Both filters appear only where the backend can apply them. On Temporal, [turn on the search
attributes](../../backends/#register-durables-search-attributes).

## See also

- [Read a run](../reading-a-run/)
- [Parity](../parity/)
