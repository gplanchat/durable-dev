# DurablePlugin (Sylius)

`gplanchat/durable-plugin` provides a Sylius-oriented admin dashboard for Durable workflow tracking.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **Tests.** This split ships its own `tests/` and `phpunit.xml`; the same files run in the monorepo
> as its `plugin` suite.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

## Features

- Workflow runs list with an outcome filter and cursor paging.
- Run details as a timeline: **one line per action**, placed in time, hatched where the wait was for
  someone to pick the work up rather than for the work itself.
- Backend-neutral: reads whichever catalog the bundle registers — Temporal, SQL, or in-memory.
- Action labels prioritise human-readable names and fall back to technical IDs only when needed.

## The panels, and why they are the same everywhere

Every Durable dashboard shows the same four panels, whichever host renders them. They are not a
matter of chrome: a panel one surface has and another lacks is a question one application can answer
about a run and another cannot, about the same run, recorded by the same backend.

1. **The state of the backend.** Three states, and an empty list means something different under
   each: no readable backend is configured; a backend is configured and cannot be reached, named and
   dated so an operator knows what to restart; or a backend answers and its journal does not outlive
   the request that renders the page — where an empty list is the correct answer, not a failure.
2. **The runs**, filterable by outcome and paged.
3. **Counters per outcome**, over the set the list is paging through — and labelled as covering that
   set, never as a total over the application's history.
4. **A selected run's recorded history**: one line per *action*, placed in time, with an interval
   spent waiting to be picked up told apart from one spent working; each event unfolds onto what the
   backend recorded with it.

Grouping into actions, measuring, telling a queue apart from work and wording a duration are decided
**once**, in `gplanchat/durable` beside the observation model. What each host decides is how to draw
it — scaling seconds to a column width is the only thing a surface owns, because a surface that
renders no markup has no column.

### What the page does not show, and why

A fact the configured backend does not have is **absent** from the page rather than rendered empty.
An empty "task queue" column would teach an operator that the run has no queue, when it is the
backend that has no such notion.

| | Temporal | SQL (DBAL) | In-memory |
| --- | --- | --- | --- |
| Task queue, namespace | recorded, not shown — see below | no such notion | no such notion |
| Grouping across continue-as-new | the workflow id | no such notion | no such notion |
| Queries | recorded | never recorded; queries are answered live | never recorded, same reason |
| Runs from another process | all of them | all of them | **none — see below** |

Task queue and namespace are not shown at all for now: they belong to one backend only, and the page
is meant to read the same on all three.

## Requirements

- PHP 8.2+
- Symfony 6.4, 7.x or 8.x
- Sylius 2.x (when used inside a Sylius application)
- `gplanchat/durable-bundle`, which wires the run catalog the dashboard reads — pulled in as a
  dependency, so `composer require gplanchat/durable-plugin` is the whole install

Whichever backend records your durable executions — a SQL database through
`gplanchat/durable-bridge-dbal`, a Temporal cluster through `gplanchat/durable-bridge-temporal`, or
the in-memory journal the bundle ships by default — the bundle registers the matching catalog and
the dashboard reads it. Nothing here requires `ext-grpc`.

### The in-memory backend reads, and says what that is worth

The in-memory catalog is wired last, only when no other backend claimed the slot. It works, and it
carries a limit the page has to state rather than hide: **an in-memory journal lives and dies with
its process.** Under PHP-FPM the request that renders the dashboard has never executed a workflow,
so the list will be empty — always, on a perfectly healthy application.

That is why the backend health line is not a bare "reachable": it says that this catalog only ever
sees runs from its own process, and that an empty list means nothing ran *here*. A blank page
without that sentence would teach an operator that nothing ran at all, which is the same mistake as
rendering an empty "task queue" column — see above.

Where it earns its keep is a long-running process: a FrankenPHP worker, a consumer command, a test.
There the catalog sees what its process executed.

If no catalog at all is registered — a backend that reads nothing — the plugin still installs, the
route and the menu entry still work, and the page says that no readable backend is configured.

## Installation in a Sylius app

```bash
composer require gplanchat/durable-plugin
```

Enable the bundle and import routes:

```php
// config/bundles.php
return [
    // ...
    Gplanchat\Durable\Plugin\DurablePlugin::class => ['all' => true],
];
```

```yaml
# config/routes/gplanchat_durable_plugin.yaml
gplanchat_durable_plugin:
    resource: '@DurablePlugin/Resources/config/routes.yaml'
```

The dashboard route is:

- `/admin/durable/dashboard`

## Development notes

- Menu entry registration listens to `sylius.menu.admin.main`.
- If no catalog is registered, the page reports it without naming any particular backend.
- Timeline labelling rules — an action is named by the event that **opens** it, because only the
  scheduling knows the name and its follow-ups carry a number:
  - Activities: `ActivityType.name` > `activityId` > generated fallback label.
  - Signals: signal name from Temporal event attributes.
  - Queries: short human-readable query state derived from Temporal event type.

### Timeline labels (before/after)

- Activities:
  - Before: `Activity: 42f1dd58-d7f8-4f84-9a09-7f8937493f3a`
  - After: `Activity: SendWelcomeEmail`
- Queries:
  - Before: `Query: WORKFLOW EXECUTION QUERY COMPLETED`
  - After: `Query: Completed`

### Label source mapping

| Event kind | Primary source | Fallback(s) |
| --- | --- | --- |
| `activity` | `ActivityTaskScheduledEventAttributes.activity_type.name` | `activityId`, then generated `activity-<scheduledId>` |
| `signal` | `WorkflowExecutionSignaledEventAttributes.signal_name` | generated `signal-<eventId>` |
| `query` | Temporal event type shortened (`EVENT_TYPE_WORKFLOW_EXECUTION_QUERY_*`) | `query-<eventId>` |
| `update` | accepted request input name (`acceptedRequest.input.name`) | meta update id, then `update-<eventId>` (technical enum labels are normalized) |

## License

MIT. See `LICENSE` in this package and `WA004`.
