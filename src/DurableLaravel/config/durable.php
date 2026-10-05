<?php

declare(strict_types=1);

/*
 * The package defaults, and the copy that `vendor:publish --tag=durable-config` drops into the
 * application. One file for both, so there is nothing to let diverge.
 *
 * WARNING: no `env()` call here. The provider loads this file as a set of default values, in a
 * standalone worker and in a test too, where `env()` exists, since it comes from
 * `illuminate/support`, but blows up on `PhpOption\Option`, which only `vlucas/phpdotenv`
 * supplies. That is the exact failure the docblock of `ResumeLock` describes about
 * `Lock::block()`: it only happens where nobody is watching. Your published copy, on the other
 * hand, always runs inside an application: put in it whatever `env()` you want.
 */

return [
    /*
     * The storage backend. This package serves three of them, and refuses the others by name rather
     * than failing on the first execution: "illuminate" puts the journal in a SQL database through
     * Laravel's database layer, "temporal" puts it in a Temporal cluster (see below), "memory" does
     * not survive the process and is only there for tests.
     */
    'backend' => 'illuminate',

    /*
     * The database connection, in the sense of config/database.php. Name one of its own: `null`
     * takes the application's default one, which works but is strongly discouraged (DUR054):
     * Durable's transactions then nest inside the application's, and a business rollback erases
     * journal events.
     */
    'connection' => null,

    /*
     * The workflow classes this application declares.
     *
     * Laravel's container has no equivalent of Symfony's per-attribute autoconfiguration, so the
     * declaration is explicit. What that does not change is the class: the one that runs on
     * `durable-bundle` runs here without a line of difference.
     *
     * Measured (§1.4): this list costs 0.14 ms and does not grow with the application, where a
     * reflection scan costs 15 ms on a thousand classes and loads them all, in every process, to
     * find five.
     *
     * @var list<class-string>
     */
    'workflows' => [],

    /*
     * The activity handler classes. Each serves the contract its #[AsActivityHandler] names, or
     * else its interfaces with #[AsActivityMethod] methods; a wrong one is refused at boot.
     */
    'activity_handlers' => [],

    /*
     * The Temporal cluster, when `backend` is "temporal".
     *
     * The DSN carries the address, the namespace and the two task queues:
     *   temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities
     *
     * This backend needs `gplanchat/durable-bridge-temporal`, which is **suggested rather than
     * required**: it installs a gRPC client and five Symfony components a Laravel application never
     * loads. The provider says so by name if the package is missing.
     *
     * The journal, the catalog and both task queues then live in the cluster, and the application's
     * queue carries nothing of Durable's. Two workers drain the cluster:
     * `php artisan durable:temporal-worker` for the workflow tasks, and
     * `php artisan durable:temporal-worker --role=activity` for the activity tasks.
     */
    'temporal' => [
        'dsn' => null,
        // Write the DurableWorkflowName and DurableExecutionId search attributes on every start,
        // so the run list can filter by workflow name and execution id. Register both on the
        // namespace first: a server refuses a start that names an unregistered attribute.
        'search_attributes' => false,
        // A container binding: the application's GuzzleHttp\ClientInterface, which
        // `transport=guzzle` in the DSN then uses: its proxy, TLS options and middleware apply to
        // gRPC. Unused by any other transport; null builds a default client.
        'guzzle_client' => null,
        // Container bindings: the application's PSR-18 client, which `transport=http` (the JSON
        // gateway) then uses instead of curl, and a PSR-17 factory implementing both request and
        // stream factories, defaulting to the client's binding. null keeps curl.
        'psr18_client' => null,
        'psr17_factory' => null,
        // A container binding: the application's PayloadCodecInterface, which encodes every
        // payload sent to Temporal and decodes every payload read (DUR055). The codec reads its
        // own key, from the application's .env; Durable reads none. null sends payloads as they are.
        'payload_codec' => null,
    ],

    /*
     * The retry ceiling for activities on the "illuminate" and "memory" backends, the same key as
     * the Symfony bundle's. An activity's own `RetryLimit` can only be stricter. `0` caps nothing:
     * an activity without a limit retries until it succeeds, which is Temporal's default. On
     * "temporal" it is not read: the cluster retries from the activity's own `RetryLimit`.
     */
    'max_activity_retries' => 0,

    'tables' => [
        'events' => 'durable_events',
        'metadata' => 'durable_workflow_metadata',
        'parent_links' => 'durable_child_workflow_parent_link',
        'runs' => 'durable_workflow_runs',
    ],

    /*
     * The queue that carries the activities and the resumes, in the sense of config/queue.php.
     * `null` takes the application's default connection and queue.
     *
     * There is no second queue: Durable's work travels on the one the application already drains,
     * with `php artisan queue:work` as its only worker.
     */
    'queue' => [
        'connection' => null,
        'name' => null,
    ],

    /*
     * The Nexus operations this application **serves**. Calling an operation has nothing to
     * declare here: the workflow asks for it.
     *
     * The key is the handler class, the value the contract it serves:
     *
     *     'handlers' => [App\Nexus\BillingHandler::class => App\Contracts\BillingService::class],
     *
     * or the handler class alone, when it names its contract with #[AsNexusServiceHandler]:
     *
     *     'handlers' => [App\Nexus\BillingHandler::class],
     *
     * If both are given and disagree, the registry refuses. So it does for an operation nobody
     * serves, and for a class in `workflows` that does not exist.
     *
     * What a handler does not serve, a workflow fulfils: it then carries
     * `#[FulfilsNexusOperation]`, and it is enough for it to be in the `workflows` list above.
     *
     * ⚠ Serving Nexus requires the "temporal" backend: it is the cluster that routes. Under any
     * other backend, the registry refuses at registration and says why, rather than failing on the
     * first call.
     */
    'nexus' => [
        'handlers' => [],
    ],

    'lock' => [
        /*
         * The cache store that carries the resume lock, in the sense of config/cache.php. `null`
         * takes the default one.
         *
         * ⚠ It must lock **across processes**. Measured on Laravel 12 with four workers and twenty
         * resumes of one execution: `database` and `file` leave no overlap at all, `array` leaves
         * fifteen out of twenty (it only excludes inside one process) and `null` as many (it
         * excludes nothing). All four implement `LockProvider`: the typing does not protect you,
         * this setting does.
         */
        'store' => null,

        /* What releases the lock when the process holding it dies. */
        'ttl' => 300,

        /*
         * The deferral of a resume whose turn is taken, in seconds.
         *
         * Measured (§1.5): on a hot execution (woken ceaselessly by signals or timers), 98.8% of
         * the resumes collide, and this delay then **is** the latency: one second turned 32 s of
         * work into 148 s of clock. On an estate of many executions, the collisions drop to 0.6%
         * and the setting no longer has any effect.
         */
        'backoff' => 1,

        /*
         * How many times in a row a resume is willing to find the turn taken before giving up
         * noisily. An endless deferral looks like an execution that is making progress.
         */
        'max_deferrals' => 50,

        /*
         * How long a resume is willing to wait for its turn.
         *
         * ⚠ This is a ceiling on **queue depth**, not a latency setting: as soon as depth ×
         * critical section duration exceeds this value, the resumes throw.
         */
        'wait' => 10,
    ],
];
