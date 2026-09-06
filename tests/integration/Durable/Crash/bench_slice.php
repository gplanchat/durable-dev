<?php

declare(strict_types=1);

/**
 * One slice of the interprocess crash bench: a whole PHP process that runs a workflow against a
 * journal on disk, and possibly dies in the middle of it.
 *
 * It is a script and not a test method because that is the entire point. A `fork()` inside PHPUnit
 * shares the parent's memory image, so anything the workflow leaks into a static, a container or a
 * closure would still be there — which is exactly the class of leak this bench is looking for. A
 * second `exec()`ed process shares nothing but the SQLite file, and the file is the journal.
 *
 * Usage:
 *   php bench_slice.php <journal.sqlite> <executionId> start|resume
 *
 * Environment:
 *   BENCH_LOG   file appended to, one line per activity actually executed
 *   BENCH_KILL  set to 1 to SIGKILL the process between the two activities (optional)
 *
 * Exit codes: 0 finished, 3 still suspended, 4 usage. A SIGKILL leaves no exit code at all, which
 * is what the caller asserts on.
 *
 * **The kill is between the activities, not inside one**, and that is a deliberate narrowing. A
 * worker that dies *while* an activity is in flight leaves a scheduled slot with no outcome, and
 * what should happen then is a redelivery — a property of the activity transport and its retry
 * policy, neither of which this bare setup has. Measured on the way here: with no transport worker
 * and `maxActivityRetries: 0`, resuming such an execution waits forever, which is the correct
 * behaviour for a runtime that has nobody to ask. It is a real question, it is not *this* question,
 * and answering both at once would leave neither answered.
 */

use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use integration\Durable\Crash\CrashBenchActivities;

require __DIR__ . '/../../../../vendor/autoload.php';

[$journalPath, $executionId, $phase] = [$argv[1] ?? null, $argv[2] ?? null, $argv[3] ?? null];

if (null === $journalPath || null === $executionId || !\in_array($phase, ['start', 'resume'], true)) {
    fwrite(STDERR, "usage: bench_slice.php <journal.sqlite> <executionId> start|resume\n");
    exit(4);
}

$log = getenv('BENCH_LOG') ?: null;
$killBetween = '1' === getenv('BENCH_KILL');

/**
 * What an activity does when it really runs — and the whole assertion of this bench is that the
 * second process never writes the first activity's line.
 */
$record = static function (string $name) use ($log): void {
    if (null !== $log) {
        file_put_contents($log, $name . "\n", FILE_APPEND | LOCK_EX);
    }
};

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $journalPath]);
$eventStore = new DbalEventStore($connection, new DurableSchema($connection));

$executor = new RegistryActivityExecutor();
$executor->register('bench.first', static function (array $payload) use ($record): string {
    $record('bench.first');

    return 'first:' . ($payload['tag'] ?? '?');
});
$executor->register('bench.second', static function (array $payload) use ($record): string {
    $record('bench.second');

    return 'second:' . ($payload['tag'] ?? '?');
});

// The handler is defined here, in the file both processes run, because replay demands that the two
// processes execute the same workflow code. A closure that differed between them would fail the
// divergence guard rather than the bench — a different measurement wearing this one's name.
//
// The kill sits between the two awaits, and it is not a workflow decision: it schedules nothing,
// journals nothing and returns nothing. It is `kill -9` arriving at the one instant where the
// journal holds the first activity's outcome and knows nothing yet of the second. Reaching in from
// outside would have to race that instant; reaching in from here hits it exactly.
$handler = static function (WorkflowEnvironment $env) use ($killBetween): array {
    $first = $env->await($env->activityStub(CrashBenchActivities::class)->first('a'));

    if ($killBetween) {
        // SIGKILL and not exit(): no destructors, no shutdown functions, no chance for anything to
        // flush a buffer that a real crash would have taken with it. A worker that is OOM-killed or
        // whose container is stopped gets exactly this much warning.
        posix_kill(posix_getpid(), SIGKILL);
    }

    $second = $env->await($env->activityStub(CrashBenchActivities::class)->second('b'));

    return ['first' => $first, 'second' => $second];
};

$runtime = new ExecutionRuntime($eventStore, new InMemoryActivityTransport(), $executor);
$engine = new ExecutionEngine($eventStore, $runtime);

try {
    $result = 'start' === $phase
        ? $engine->start($executionId, $handler, 'CrashBench')
        : $engine->resume($executionId, $handler, 'CrashBench');
} catch (WorkflowSuspendedException) {
    exit(3);
}

echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
exit(0);
