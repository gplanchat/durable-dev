---
title: The dashboard in Filament
weight: 25
---

# The dashboard in Filament

To follow the workflows of a Laravel application from a Filament panel, install
`gplanchat/durable-filament` ([Packages](../../packages/)) and register the plugin on the panel:

```php
use Gplanchat\Durable\Filament\DurableFilamentPlugin;

$panel->plugin(DurableFilamentPlugin::make());
```

The panel then shows **Durable runs** in its navigation. It works on Filament 3 and 4, reads the
catalog that `gplanchat/durable-laravel` binds (in memory, Illuminate or Temporal), and is
read-only.

## Screenshots

![The Filament Durable runs page: backend state, filters, counters per outcome and the list of runs](/images/dashboard/filament-runs.png)

*Durable runs, over four runs of an order workflow. The counters include one run waiting for a worker.*

![The Filament run page of an execution waiting on a timer, with its History timeline](/images/dashboard/filament-run.png)

*The run page of order/4244: what it waits on, and a History timeline where `reserveStock` is hatched for the time it spent in the queue.*

## What the page offers

- **The backend state**, dated.
- **Counters** per outcome, with a **Waiting for a worker** count, over the runs on the page.
- **Filters** on outcome, and on workflow name and execution id prefix where the backend can apply
  them. The filters stay set from one page to the next.
- **The run list**, 20 a page, forward by cursor. Each row carries the execution id (a link to the run), the outcome, the workflow, the start date and a note (`waiting for a worker`, `waiting on …`).
- **A run page**, opened from a row: outcome, what it waits on, its [Nexus operations](../../nexus/) where the
  catalog can list them, and its history, one block per action, under a timeline. See [Read a
  run](../reading-a-run/).

## What it does not show

There is no worker presence panel. See [Parity](../parity/).

## Language and payloads

English and French. A payload unfolds on demand and is masked by what your application binds
`Gplanchat\Durable\Observation\PayloadRedactorInterface` to in the container; the key-name redactor
applies otherwise.
