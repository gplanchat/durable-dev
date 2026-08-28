# DurableModule (Magento 2 / Mage-OS)

`gplanchat/durable-magento` runs Durable workflows inside a Magento application:
`Gplanchat_DurableModule` in `bin/magento module:status`.

It declares workflow and activity classes to the runtime, assembles the engine for a Magento
process, ships the workers as `bin/magento` commands, and adds a read-only admin screen under
**System > Durable processes > Process history**.

## Release state

No tagged release carries this package yet. Until one does, require it as `:dev-main`.

## Requirements

- PHP 8.2+
- Magento 2.4.x or Mage-OS
- `gplanchat/durable` — pulled in as a dependency
- `gplanchat/durable-bridge-temporal` for anything that must outlive a process

## Two backends, and Composer enforces it

Magento reaches **in-memory** and **Temporal**, and nothing else. The module declares `conflict` on
both SQL bridges, because `Magento\Framework\App\ResourceConnection` is neither Doctrine DBAL nor
Illuminate's connection. This is the final shape, not a stage: an incoherent install is refused by
the dependency solver rather than by a runtime check nobody reads.

Which backend you get is decided by a DSN in `app/etc/env.php`, not by a setting:

```php
'durable' => [
    'temporal' => ['dsn' => 'temporal://temporal:7233?namespace=default&tls=0'],
],
```

Without it the journal lives in the process that writes it, and dies with it — fine for a console
command, ruinous for anything served by PHP-FPM.

## Installation

```bash
composer require gplanchat/durable-magento:dev-main gplanchat/durable-bridge-temporal
bin/magento module:enable Gplanchat_DurableModule
bin/magento setup:upgrade
```

## Declaring what runs

Magento's container has no equivalent of Symfony's tag autoconfiguration, so declaration is
explicit — two arrays in your own module's `di.xml`:

```xml
<type name="Gplanchat\DurableModule\Runtime\RuntimeFactory">
    <arguments>
        <argument name="workflowClasses" xsi:type="array">
            <item name="place_order" xsi:type="string">Acme\Shop\Workflow\PlaceOrder</item>
        </argument>
        <argument name="activityHandlers" xsi:type="array">
            <item name="order" xsi:type="object">Acme\Shop\Activity\OrderActivities</item>
        </argument>
    </arguments>
</type>
```

The *contract* is not declared: the factory reads each handler's interfaces and keeps those carrying
`#[AsActivityMethod]`. One declaration fewer to get wrong, and the activity names stay the
attributes'.

A workflow class written for the Symfony bundle runs here unmodified — everything below the ports is
the same component.

## Workers are commands, not queue consumers

```bash
bin/magento durable:worker --role=journal   --time-limit=3600
bin/magento durable:worker --role=activity  --time-limit=3600
```

One process, one role: these are two distinct Temporal task queues, and their concurrency is tuned
apart. An operator supervises them with whatever already supervises every other long-running Magento
process.

## Start executions on the cluster, not in the request

An observer that hands the execution to Temporal and returns:

```php
$this->runtimeFactory->workflowClient()->startAsync(
    PlaceOrder::class,
    ['orderId' => $order->getIncrementId()],
    'order-' . $order->getIncrementId(),
);
```

Starting it inline would kill it with the request, which is the very failure this integration exists
to remove. And an observer that throws refuses a sale that already happened: a workflow that fails
to start is an operational incident, not a reason to reject the customer — rejecting them would not
give the money back either.

## The admin screen

A standard Magento grid — paging, bookmarks, column controls, export, and a multi-select status
filter whose options come from the status enum itself. Selecting a run opens its detail: a timeline
with one line per action, and the journal beneath it, each line unfolding onto what the backend
recorded with it.

⚠ **The grid reads a 200-run window and pages inside it.** The grid pages by offset, the cluster by
continuation cursor, and the two do not translate without state. Beyond the window the grid tells
the truth about what it shows, but does not show everything. The filters filter the window, not the
cluster, whose visibility query is a surface of its own.

## What this module does not do, and why

**Nothing rides Magento's `MessageQueue`.** On Temporal an activity is a Temporal command and a
resume is a workflow task; a topic here would be a second queue for an operator to supervise, for
nothing. Magento has no native journal of its own and will not get one.

**There is no SQL journal on `ResourceConnection`**, and none is planned — see the two backends
above.

## License

MIT. See [LICENSE](LICENSE).
