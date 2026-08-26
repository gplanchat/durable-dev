---
title: 'Durable — durable execution for PHP'
type: docs
bookToc: false
---

# Write a business process. Survive everything else.

A payment is charged, then the process must reserve stock, then email the customer. The worker is
redeployed between the second and third step. What happened to the order?

With **Durable**, nothing happened to it. The workflow picks up exactly where it stopped — the
charge is not replayed, the reservation is not lost, and the email still goes out.

```php
final class CheckoutWorkflow
{
    #[WorkflowMethod]
    public function __invoke(WorkflowEnvironment $env, string $orderId): string
    {
        $payment = $env->await($env->activity('charge', ['orderId' => $orderId]));
        $env->await($env->activity('reserve-stock', ['orderId' => $orderId]));
        $env->timer(300.0);                       // wait five minutes — process restarts are fine
        $env->await($env->activity('send-receipt', ['payment' => $payment]));

        return $payment;
    }
}
```

That `timer()` is a five-minute wait that costs no process, no cron, no queue message with a delay
that gets lost. Deploy in the middle of it and the workflow resumes on the other side.

---

## What you no longer write

{{< columns >}}

### Without Durable

- a state column, and the migration that adds the next state
- a cron that scans rows in `pending_*`
- a retry counter, and the dead-letter table
- a guard against the job that ran twice
- a way to know why order 4242 stopped three days ago

<--->

### With Durable

- a method that reads top to bottom
- retries, timeouts and backoff as **options**
- an execution history you can replay and inspect
- a `try/catch` around a cancellation, to compensate
- a workflow you unit-test with no server at all

{{< /columns >}}

The trick is **replay**: your workflow code re-runs from the start on every resume, but every step
already recorded returns its recorded result instead of running again. You write straight-line
code; the engine makes it resumable.

---

## Three packages, take what you need

{{< columns >}}

{{< card title="gplanchat/durable" subtitle="The library. Workflows, activities, timers, the event journal. Two runtime dependencies: a PSR clock and a PSR logger. No framework." />}}

<--->

{{< card title="gplanchat/durable-bundle" subtitle="The Symfony integration. Autoconfigures your workflows and activities, wires Messenger transports, adds worker commands and a profiler panel." />}}

<--->

{{< card title="gplanchat/durable-bridge-temporal" subtitle="The Temporal driver. Speaks gRPC to a Temporal cluster directly — no official PHP SDK, no RoadRunner." />}}

{{< /columns >}}

The library runs on its own with an in-memory backend, which is what your tests use. Add the bridge
when you want executions that outlive the process. See [Packages](docs/packages/) for what each one
brings and what it needs.

---

## Start here

{{< columns >}}

**New to this**
[Concepts](docs/concepts/) explains workflows, activities and replay in plain language — ten minutes,
no code.

<--->

**Ready to build**
[Getting started](docs/getting-started/) installs the bundle, wires Messenger and runs a first workflow.

<--->

**Evaluating**
[Backends](docs/backends/) compares the in-memory and Temporal backends, and states plainly what each
one supports.

{{< /columns >}}

---

## Everything else

| | |
|---|---|
| [Packages](docs/packages/) | the library, the bundle, the Temporal driver — what to install and when |
| [Creating a workflow](docs/workflows/) | `WorkflowEnvironment`, signals, queries, updates, child workflows |
| [Creating activities](docs/activities/) | activity contracts, dependency injection, the typed stub |
| [Failures and retries](docs/failures/) | what the journal records, and why an activity stopped retrying |
| [Cancellation](docs/cancellation/) | raising cancellation inside the workflow so it can compensate |
| [Options and value objects](docs/options/) | retry limits, timeouts, cron schedules, search attributes |
| [Testing workflows](docs/testing/) | unit tests with no server, and the suite that runs against a real one |
| [Configuration reference](docs/configuration/) | every `durable.yaml` key |

---

This site is the **user guide**. Architecture decision records (**DUR**) and working agreements
(**WA**) live in the repository for contributors, under `documentation/adr/` and `documentation/wa/`.

If something here is unclear or wrong, open an issue or a pull request.
