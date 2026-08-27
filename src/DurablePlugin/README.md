# DurablePlugin (Sylius)

`gplanchat/durable-plugin` provides a Sylius-oriented admin dashboard for Durable workflow tracking.

## Features

- Workflow runs list with an outcome filter and cursor paging.
- Run details with recorded history grouped in lanes.
- Backend-neutral: reads whichever catalog the bundle registers.
- Lane labels prioritise human-readable names and fall back to technical IDs only when needed.

### What the page does not show, and why

A fact the configured backend does not have is **absent** from the page rather than rendered empty.
An empty "task queue" column would teach an operator that the run has no queue, when it is the
backend that has no such notion.

| | Temporal | SQL (DBAL) |
| --- | --- | --- |
| Task queue, namespace | recorded, not shown — see below | no such notion |
| Grouping across continue-as-new | the workflow id | no such notion |
| Query lane | recorded | never recorded; queries are answered live |

Task queue and namespace are not shown at all for now: they belong to one backend only, and the page
is meant to read the same on both.

## Requirements

- PHP 8.2+
- Symfony 6.4, 7.x or 8.x
- Sylius 2.x (when used inside a Sylius application)
- `gplanchat/durable-bundle`, which wires the run catalog the dashboard reads — pulled in as a
  dependency, so `composer require gplanchat/durable-plugin` is the whole install

Whichever backend records your durable executions — a SQL database through `gplanchat/durable-bridge-dbal`, or a
Temporal cluster through `gplanchat/durable-bridge-temporal` — the bundle registers the matching
catalog and the dashboard reads it. Nothing here requires `ext-grpc`.

Without a readable backend the plugin still installs, the route and the menu entry still work, and
the page says that no readable backend is configured.

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
- Timeline labeling rules:
  - Activity lanes: `ActivityType.name` > `activityId` > generated fallback label.
  - Signal lanes: signal name from Temporal event attributes.
  - Query lanes: short human-readable query state derived from Temporal event type.

### Timeline labels (before/after)

- Activities:
  - Before: `Activity: 42f1dd58-d7f8-4f84-9a09-7f8937493f3a`
  - After: `Activity: SendWelcomeEmail`
- Queries:
  - Before: `Query: WORKFLOW EXECUTION QUERY COMPLETED`
  - After: `Query: Completed`

### Label source mapping

| Lane kind | Primary source | Fallback(s) |
| --- | --- | --- |
| `activity` | `ActivityTaskScheduledEventAttributes.activity_type.name` | `activityId`, then generated `activity-<scheduledId>` |
| `signal` | `WorkflowExecutionSignaledEventAttributes.signal_name` | generated `signal-<eventId>` |
| `query` | Temporal event type shortened (`EVENT_TYPE_WORKFLOW_EXECUTION_QUERY_*`) | `query-<eventId>` |
| `update` | accepted request input name (`acceptedRequest.input.name`) | meta update id, then `update-<eventId>` (technical enum labels are normalized) |

## License

MIT. See `LICENSE` in this package and `WA004`.
