---
title: The Symfony web profiler panel
weight: 30
---

# The Symfony web profiler panel

To see which workflows a single request dispatched and what their [journals](../../glossary/)
recorded so far, open the **Durable** panel of the web profiler. It belongs to `gplanchat/durable-bundle`
and appears wherever the web profiler is installed.

It is not the [dashboard](../) in a smaller frame. The dashboard lists the runs of an application;
this panel lists the runs of **one request**, so it has no backend banner, no counters and no
filters.

## Open the panel

1. Load a page of your application that dispatches a workflow, with the debug toolbar enabled.
2. Click the Durable item of the toolbar. It shows the number of dispatches and of journal events
   collected on the request.
3. Read the **Summary** tab first: one row per execution, with its workflow id, type, status and
   number of events.

The **Executions** tab opens each execution: its event history, its Nexus operations, its journal
timeline and its process trace. The **Overview** tab draws every process on one time frame and
lists the Messenger dispatches of the request.

## Load a journal the request did not dispatch

The panel collects the execution ids seen in the request's process trace: the Messenger dispatches, and the workflows and activities run in the same process. To add
one that a worker or another request started, put `durable_execution` on the URL of the request
you profile, with the execution id, or several separated by commas:

```
https://shop.localhost/checkout?durable_execution=0195f3c2-7a1e-7d2a-9c1b-3f6a2e8b5d40
```

The parameter goes on the profiled request itself, the one that fills the toolbar. Opening it on the
profiler URL afterwards changes nothing. Reload the page with it to produce a new profile. A
request reads at most 20 ids, and each id costs one journal read.

## When the journal is empty

If the request dispatched a message but the panel shows no event, the message is probably still
queued: with an asynchronous transport, the handler has not run in this process. The panel says
**Journal still empty**. Run a worker, then reload with `durable_execution` set.

## What differs from the dashboard

- **Wording.** The status column reads Finished, Queued (no journal yet), Pending, Cancellation requested or
  Continue as new, and the panel is in English only.
- **Timeline.** One row per event, in journal order. Queue time is not hatched and a failed event
  is not painted red.
- **Payloads.** They are masked like the dashboard's, and always shown open.
- **Limits.** 500 events per journal, and a warning when a Nexus operations table is cut.

[Parity](../parity/) lists every difference.
