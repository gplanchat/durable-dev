---
title: Options and value objects
weight: 32
---

# Options and value objects

Scheduling options — retry limits, timeouts, task queues, cron schedules, search attributes — are
**value objects**, not primitives. Each validates what it can at construction, so a mistake
surfaces where you wrote it rather than as a server rejection, a silently rewritten value, or an
execution that waits forever.

Every rule enforced here was **probed against a running Temporal server** before being written.
Where the server is permissive, these objects usually are too; where they are stricter, the
docblock says why.

---

## Duration

A length of time. Replaces the `?float …Seconds` fields.

```php
use Gplanchat\Durable\Duration;

Duration::seconds(30);
Duration::milliseconds(250);
Duration::minutes(2.5);
Duration::hours(1);
Duration::zero();                       // no wait
Duration::infinity();                   // no bound at all — the default deadline of await()
```

`infinity()` is a **value**, not an absence. It compares (`shortest()`, `isLongerThan()`), travels
through configuration, and lets code that computes a deadline stop writing a special case for "no
bound". It is not a wire duration: `timer()` rejects it, because a timer that never fires is a
command in history for a wake-up that never comes.

It also accepts native and Carbon values, without depending on Carbon:

```php
Duration::of(new DateInterval('PT90S'));          // CarbonInterval extends DateInterval
Duration::of(CarbonInterval::minutes(5));
Duration::until($deadline);                       // Carbon implements DateTimeInterface
Duration::until($deadline, $from);
Duration::from($anything);                        // Duration|DateInterval|DateTimeInterface|int|float
$duration->toDateInterval();
```

`of()` takes a **length**, `until()` takes an **instant**. A `DateTimeInterface` only becomes a
duration once measured against another instant, which is why they are two methods and not one.

A negative duration is rejected, and so is a computed `INF` or `NAN` — an infinite duration is
asked for by name. Calendar units (years, months) have no fixed length and are
resolved against a fixed UTC anchor, so prefer days, hours and minutes for a bound.

---

## RetryLimit

How far you are willing to retry an activity.

```php
use Gplanchat\Durable\Activity\RetryLimit;

RetryLimit::unlimited();        // no bound on the number of attempts (the default)
RetryLimit::ofAttempts(3);      // three attempts in total
RetryLimit::ofRetries(2);       // two retries — that is three attempts
RetryLimit::once();             // any failure is final
```

> [!WARNING]
> **Unlimited is the default**, matching a Temporal `RetryPolicy` with no `maximum_attempts`. An
> activity that always fails and does not bound its attempts **will not fail the workflow** — it
> retries forever. Only a non-retryable exception, a timeout, or cancellation stops it.
>
> Pass `RetryLimit::once()` when you want a failure to be final.

`ofAttempts(0)` is rejected: an unbounded limit is `unlimited()`, not zero. `ofRetries(0)` means
"no ceiling", which is the meaning that knob has always had in the bundle configuration.

---

## ActivityTimeouts

The four bounds of an activity, taken together — because each bounds a different segment of its
life:

```
scheduled ──schedule-to-start──▶ started ──start-to-close──▶ finished
└─────────────────── schedule-to-close ─────────────────────────┘
                     heartbeat: longest silence tolerated while running
```

```php
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Duration;

ActivityTimeouts::none();                              // the backend decides
ActivityTimeouts::attempt(Duration::seconds(30));      // the common case: bound one attempt

(new ActivityTimeouts(
    scheduleToStart: Duration::seconds(10),
    startToClose:    Duration::minutes(5),
    scheduleToClose: Duration::minutes(30),
    heartbeat:       Duration::seconds(30),
));
```

A heartbeat longer than `startToClose` is rejected: the attempt would end before the first missed
heartbeat, so the bound would be dead.

Temporal requires a closing bound. When none is set, the bridge supplies a default — that fallback
is named `executionBoundOr()` rather than hidden inside command construction.

---

## Putting activity options together

```php
use Gplanchat\Durable\Activity\ActivityOptions;

// 3 attempts, 30s each, 1s before the first retry.
$options = ActivityOptions::of(3, 30, 1, [PaymentRefusedException::class], 'payments');
```

`of()` is the constructor written in the order you think in: how many attempts, and how long each
one may take. It accepts the scalar equivalents — an **int** is a number of attempts, a bare
**duration** is the `startToClose` bound of one attempt, a **float** is seconds. Nothing is magic:
`of(0)` is rejected rather than read as "unlimited". The long form stays available and is strictly
equivalent, for when you want every intent named:

```php
use Gplanchat\Durable\Activity\{ActivityOptions, ActivityTimeouts, RetryLimit};
use Gplanchat\Durable\{Duration, TaskQueue};

$options = new ActivityOptions(
    RetryLimit::ofAttempts(3),
    initialInterval: Duration::seconds(1),
    nonRetryableExceptions: [PaymentRefusedException::class],
    taskQueue: TaskQueue::named('payments'),
    timeouts: ActivityTimeouts::attempt(Duration::seconds(30)),
);

$result = $env->await($env->activity('charge', ['orderId' => $id], $options));
```

The retry interval grows by `backoffCoefficient` and is capped. With no explicit cap, Temporal's
default applies: **100 × the initial interval**. That cap matters once attempts are unlimited —
without it an exponential backoff diverges.

---

## WorkflowTimeouts

The three workflow bounds, which nest:

```
execution ─┬─ run 1 ─┬─ run 2 (continue-as-new, retry) ─ …
           │         └─ task: one worker decision round-trip
           └────────────── execution: the whole chain
```

```php
use Gplanchat\Durable\{Duration, WorkflowTimeouts};

WorkflowTimeouts::none();
WorkflowTimeouts::run(Duration::minutes(10));

new WorkflowTimeouts(
    execution: Duration::hours(1),
    run:       Duration::minutes(10),
    task:      Duration::seconds(10),
);
```

A run bound longer than the execution bound is **rejected**. The server does not reject it — it
silently rewrites the run bound down to the execution bound, so the configuration you wrote is not
the one that applies. Better to hear about it.

`ContinueAsNewOptions` refuses an execution bound outright: the new run belongs to the current
execution and inherits it. Use `withoutExecutionBound()` to reuse a `WorkflowTimeouts` there.

---

## TaskQueue and WorkflowNamespace

```php
use Gplanchat\Durable\{TaskQueue, WorkflowNamespace};

TaskQueue::named('payments-activities');
WorkflowNamespace::named('billing');
```

Both refuse a blank name, edge whitespace, and control characters. The server accepts all three,
but they are never intentional — and for a task queue the consequence is silent: work is queued to
a name nobody polls, and the execution simply waits, with nothing in the logs.

> [!NOTE]
> Neither object catches a typo that is still a valid name — `payments-activites` for
> `payments-activities`. A task queue fails silently; a namespace fails loudly with
> `NOT_FOUND`. Catching the former would need a registry of the queues actually served.

Namespace comparison is **case-sensitive**, as on the server: `Billing` and `billing` are two
different namespaces.

---

## CronSchedule

A recurrence, validated at construction — a typo would otherwise only appear when the server
refuses the first start.

```php
use Gplanchat\Durable\{CronSchedule, Duration};

CronSchedule::parse('0 9 * * 1-5');
CronSchedule::daily();                              // also hourly, weekly, monthly, yearly
CronSchedule::every(Duration::minutes(90));         // @every 1h30m
CronSchedule::dailyAt(9, 30);                       // 30 9 * * *
CronSchedule::dailyAt(9)->inTimeZone('Europe/Paris');
```

> [!WARNING]
> Without a time zone the server reads the expression in **UTC** — rarely what "every day at 9" is
> meant to mean. `inTimeZone()` emits the `CRON_TZ=` prefix the server expects.

Validation mirrors the server's, expression by expression: field count, characters, ranges, and
**reachability** — `0 0 31 4 *` is refused because April has thirty days. Day of week runs 0 to 6,
so `7` for Sunday is refused. `?` is accepted anywhere as a synonym for `*`.

The two most likely mistakes are named in the error: a six-field expression (a Quartz cron copied
from elsewhere) and a schedule with no occurrence.

See [Recurring workflows](#recurring-workflows) below for starting one.

---

## SearchAttributes

What an execution can be found by.

```php
use Gplanchat\Durable\{SearchAttributes, WorkflowStartOptions};

$attributes = SearchAttributes::none()
    ->keyword('OrderId', 'ORD-4242')
    ->int('Amount', 4242)
    ->bool('Priority', true)
    ->double('Ratio', 0.75)
    ->text('Note', 'gift wrapping')
    ->datetime('DueAt', new DateTimeImmutable('2026-01-01'))
    ->keywordList('Tags', ['gift', 'express']);

$client->startAsync('CheckoutWorkflow', $input, $executionId, new WorkflowStartOptions(
    searchAttributes: $attributes,
));
```

The object is immutable: each call returns a new instance.

Two of the three server rules are checked locally:

- **the value must match the type** — an `Int` given a string is refused before the round trip;
- **sixteen system attributes are read-only** (`RunId`, `WorkflowId`, `TaskQueue`, `StartTime`, …).
  `BuildIds`, `BinaryChecksums` and `TemporalChangeVersion` are *not* among them and can be written.

The third cannot be checked here: **the attribute must be registered in the namespace**. That would
mean reading the namespace registry. The server answers
`has no mapping defined for search attribute`.

```bash
temporal operator search-attribute create --name OrderId --type Keyword
temporal operator search-attribute create --name Amount  --type Int
```

---

## Recurring workflows

```php
use Gplanchat\Durable\{CronSchedule, WorkflowStartOptions};

$client->startCron('NightlyReconciliation', $input, $executionId, CronSchedule::dailyAt(2));

// or through the options object, alongside timeouts and search attributes
$client->startAsync('NightlyReconciliation', $input, $executionId, new WorkflowStartOptions(
    cronSchedule: CronSchedule::dailyAt(2)->inTimeZone('Europe/Paris'),
));
```

A Temporal cron is **not an external scheduler**: it is the same logical execution, relaunched by
the server with a fresh history at each due time. The next run does not start until the previous
one has finished — a missed occurrence is **skipped, not caught up**.

Child workflows accept the same schedule through `ChildWorkflowOptions`.

> [!NOTE]
> Cron is a Temporal capability. The in-memory backend has no scheduler and does not support it.

---

## Migrating from the previous API

Named arguments changed with the value objects. A call that was not migrated fails immediately and
loudly, never silently.

| Before | Now |
|---|---|
| `maxAttempts: 3` | `RetryLimit::ofAttempts(3)` as the first argument |
| `maxAttempts: 1` | `RetryLimit::once()` |
| `maxAttempts: 0` | `RetryLimit::unlimited()` — and it is the default |
| `->withMaxAttempts(3)` | `->withRetryLimit(RetryLimit::ofAttempts(3))` |
| `initialIntervalSeconds: 1.0` | `initialInterval: Duration::seconds(1)` |
| `maximumIntervalSeconds: 60.0` | `maximumInterval: Duration::seconds(60)` |
| `startToCloseTimeoutSeconds: 30.0` | `timeouts: ActivityTimeouts::attempt(Duration::seconds(30))` |
| `scheduleToStartTimeoutSeconds`, `scheduleToCloseTimeoutSeconds`, `heartbeatTimeoutSeconds` | named arguments of `ActivityTimeouts` |
| `workflowRunTimeoutSeconds: 600.0` | `timeouts: WorkflowTimeouts::run(Duration::minutes(10))` |
| `workflowExecutionTimeoutSeconds`, `workflowTaskTimeoutSeconds` | named arguments of `WorkflowTimeouts` |
| `taskQueue: 'payments'` | `taskQueue: TaskQueue::named('payments')` |
| `namespace: 'billing'` | `namespace: WorkflowNamespace::named('billing')` |
| `cronSchedule: '0 9 * * *'` | `cronSchedule: CronSchedule::parse('0 9 * * *')` |
| `searchAttributes: ['OrderId' => 'x']` | `SearchAttributes::none()->keyword('OrderId', 'x')` |

> [!CAUTION]
> **Behaviour change, not just signatures.** `maxAttempts: 0` used to mean *no retry* and now means
> *unlimited*, matching Temporal. An activity that always fails and does not bound its attempts no
> longer fails the workflow. Review any activity that relied on the old default and pass
> `RetryLimit::once()` where a failure should be final.

Twelve `with*()` methods on `ActivityOptions` that nothing called were removed. `withRetryLimit()`
and `withTimeouts()` remain.

Search attributes had a second, quieter problem: they were journaled and **never sent to the
server**. They now reach it, so an attribute that was silently dropped may now be rejected as
unregistered. Register it, or remove it.
