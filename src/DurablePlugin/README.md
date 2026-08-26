# DurablePlugin (Sylius)

`gplanchat/durable-plugin` provides a Sylius-oriented admin dashboard for Durable workflow tracking.

## Features

- Workflow runs list with search and status filters.
- Run details with timeline lanes and recent events.
- Optional live data from Temporal via `gplanchat/durable-bridge-temporal`.
- Timeline labels prioritize human-readable names (`ActivityType.name`) and fall back to technical IDs only when needed.

## Requirements

- PHP 8.2+
- Symfony 6.4, 7.x or 8.x
- Sylius 2.x (when used inside a Sylius application)
- Durable bridge services (`durable.temporal.*`) for live data

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
- If Temporal is unavailable, the provider returns an empty list with a degraded status message.
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
