## Why

[OST003 §3](../../../documentation/ost/OST003-php-ecosystem-integrations.md) puts Laravel in Tier 1
— *foreign container, foreign queue, a package written from the bootstrap up* — and is unusually
blunt about what the entry cannot be:

> `durable-workflow/workflow` (formerly `laravel-workflow/laravel-workflow`) is durable execution on
> Laravel queues: `yield` as the checkpoint, own storage, no server, explicitly inspired by Temporal
> and Azure Durable Functions, 1 000+ stars.

The square marked *a durable engine for Laravel* is occupied, by something good at it. OST003 names
what is left, and it is not a smaller version of the same product: **the backend choice**. The same
workflow code against a Temporal cluster *or* against one SQL database, and a mixed
Symfony / Sylius / Akeneo estate sharing a single engine with the Laravel application.

### What has already landed, and what that leaves

[OST004 §5](../../../documentation/ost/OST004-what-is-not-built-yet.md) registers Laravel as
*"a bootstrap, plus a fourth adapter family"*, blocked on nothing. **Half of that is now written.**
`gplanchat/durable-bridge-illuminate` ships the four stores on `Illuminate\Database\Connection`,
each extending the conformance suite DUR041 requires, the journal one joining the replay tier. It
also ships `Queue\ResumeLock`, the per-execution exclusion, and a migration for its four tables.

What it deliberately does not ship, its own README says in as many words:

> **A queue, and jobs to put on it.** `ResumeLock` is the exclusion, not the plumbing.
>
> **The Durable service provider.** Registering stores, binding ports, adding worker commands —
> that belongs to the Laravel integration package.

So OST004's row is stale by half: the adapter family is done, and what remains is the bootstrap
alone. This change is that bootstrap.

### The gap is already advertised

The chooser on the published home page hands `composer require gplanchat/durable-laravel
gplanchat/durable-bridge-illuminate` to anyone who picks Laravel. The second package exists. **The
first does not.** The site is not wrong about the intent — it is early by one package, and this is
the one.

## What Changes

- A Laravel package SHALL register workflow and activity classes with the Durable runtime. Laravel's
  container has no equivalent of Symfony's attribute autoconfiguration, so declaration is explicit,
  and a workflow class written for `durable-bundle` SHALL run here unmodified.
- Activity dispatch and workflow resume SHALL ride **Laravel's own queue** — `illuminate/queue`
  jobs, dispatched on the connection the application already configures — rather than a second queue
  introduced beside it.
- The worker SHALL be the one an operator already supervises: `php artisan queue:work`. Any artisan
  command this package adds SHALL be for inspection, never a second process model to learn.
- Timers SHALL ride the queue's own delay, the way the DBAL backend rides Messenger's `DelayStamp`.
- Two workers SHALL NOT replay the same execution at once. The hazard is the one
  [DUR030](../../../documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md) names —
  a forked journal and duplicated activities — and the exclusion already exists as
  `Queue\ResumeLock`. **How a queued job should wait is a design question, not a given:** blocking a
  worker for ten seconds and releasing the job back to the queue are both defensible, and they are
  not equally cheap. `design.md` decides it, with a measurement.
- Configuration SHALL be a publishable `config/durable.php`, and the service provider SHALL bind the
  four storage ports from it.
- The package SHALL support the **in-memory** and **Illuminate** backends. **Temporal on Laravel is
  not decided here**, and the reason is measured rather than assumed: `gplanchat/durable-bridge-temporal`
  requires `symfony/messenger`, `symfony/dependency-injection`, `symfony/http-kernel` and
  `symfony/config`, used across seven files — a bundle, a DI extension, a services file and four
  Messenger transports. Whether that is dead weight to accept, a split to make, or a combination to
  refuse is `design.md`'s question.
- **BREAKING** no. Nothing already shipped changes shape.

### The package name is decided here

The repository gives two answers, and a third is what the Laravel ecosystem would expect:

| Where | Name |
|---|---|
| The published home page chooser | `gplanchat/durable-laravel` |
| Laravel community convention (`spatie/laravel-permission`, `laravel/horizon`) | `gplanchat/laravel-durable` |
| [OST003 §3](../../../documentation/ost/OST003-php-ecosystem-integrations.md) | *"has to lead with `gplanchat/`"*, and must not be skimmed as `durable-workflow/workflow` |

This change picks **`gplanchat/durable-laravel`**, for the reason the Magento change picked
`gplanchat/durable-magento` over `gplanchat/module-durable`: the family prefix is what a reader of
the site, the docs and Packagist has already learned, and the six shipped packages all lead with it.
The host's convention governs what a Laravel developer actually looks at — `config/durable.php`, a
`DurableServiceProvider` discovered by package auto-discovery, `php artisan vendor:publish
--tag=durable-config`.

OST003's second consequence is a documentation duty, not a naming one: the docs SHALL name
`durable-workflow/workflow` rather than hope nobody notices the collision.

### Not in scope

- **A Filament dashboard.** `durable-plugin` observes Sylius runs; the Laravel equivalent rides
  Filament, and it is a separate change that needs this one to exist first.

  **And it is a separate *package*, decided here.** `gplanchat/durable-filament` SHALL require
  `gplanchat/durable-laravel`, and this package SHALL NOT require, suggest or detect Filament. The
  precedent is exact and one-directional — `durable-plugin` requires `durable-bundle`, and
  `durable-bundle` names the plugin nowhere — so a Laravel application without Filament never hears
  of it. Laravel makes it cleaner still: package auto-discovery registers the dashboard's provider
  on install, and not installing it leaves no trace, where Symfony needs a line in `bundles.php`.

  What this rules out is the shape the shortcut suggests: **one package with
  `suggest: filament/filament` and a `class_exists(\Filament\Panel::class)` guard**. That puts dead
  code in every application and makes this package's test suite depend on a Filament install.

- **A Telescope watcher, and a Pulse card.** Named here because the previous point does not cover
  them, and because they are the *other* pattern this repository uses. `durable-bundle` carries the
  Symfony profiler panel **inside itself**, activated by `suggest: symfony/web-profiler-bundle` —
  a panel that grafts onto a tool the application already has does not earn its own package, where
  a whole interface does. So a Telescope watcher would live **in** `gplanchat/durable-laravel`
  under `suggest: laravel/telescope`, and Pulse recorders likewise. Neither is in this change:
  Telescope is the better vehicle than the Symfony panel it would mirror — it records from requests,
  queued jobs *and* artisan commands, so an execution's timeline is continuous instead of stitched
  back together from requests — and that is a reason to give it its own slice, not a corner of this
  one.
- **The API Platform state processor.** OST003 §3 makes it *"written once and collected twice"* —
  one processor over Symfony and Laravel. It is worth more as its own change than as a corner of
  this one, and it needs this package's wiring underneath before it can be collected the second time.
- **Statamic and Bagisto.** OST003 §6 has them riding the Laravel integration rather than adding
  their own. Riding it is a claim to make once it carries someone.
- **A CI bench.** OST003 §1 prices a bench as *"paid on every upstream release, forever"*, and
  §2 sets the discipline: none earns one until somebody runs one for real. Whether this change opens
  a `laravel/` overlay beside `sylius/` and `magento/` is `design.md`'s call, and the default is no.
