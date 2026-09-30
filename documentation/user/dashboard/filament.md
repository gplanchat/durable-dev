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

## What the page offers

- **The backend state**, dated.
- **Counters** per outcome, with a **Waiting for a worker** count, over the runs on the page.
- **Filters** on workflow name and on execution id prefix, where the backend can apply them.
- **The run list**, 20 a page, forward by cursor. Each row carries the execution id (a link to the run), the outcome, the workflow, the start date and a note (`waiting for a worker`, `waiting on …`).
- **A run page**, opened from a row: status, what it waits on, its [Nexus operations](../../nexus/) where the
  catalog can list them, and its history, one block per action, under a timeline. See [Read a
  run](../reading-a-run/).

## What it does not show

There is no filter on outcome, and no worker presence panel. See [Parity](../parity/).

## Language and payloads

English and French. A payload unfolds on demand and is masked by what your application binds
`Gplanchat\Durable\Observation\PayloadRedactorInterface` to in the container; the key-name redactor
applies otherwise.
