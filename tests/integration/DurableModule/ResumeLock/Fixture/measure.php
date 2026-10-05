<?php

declare(strict_types=1);

/*
 * The numbers of #732, from real processes against the MySQL `DURABLE_TEST_MYSQL` names:
 *
 *   DURABLE_TEST_MYSQL=root:root@127.0.0.1:33732/journal php measure.php [runs]
 *
 * All times are milliseconds, taken from `hrtime(true)`, the monotonic clock every process of the
 * machine shares.
 */

use integration\DurableModule\ResumeLock\Harness;

require \dirname(__DIR__, 5) . '/vendor/autoload.php';

null === ($reason = Harness::unavailable()) || exit($reason . "\n");
$runs = (int) ($argv[1] ?? 10);

function summary(string $label, array $ms): void
{
    sort($ms);
    printf("%-58s n=%-3d min %7.1f  median %7.1f  max %7.1f\n", $label, \count($ms), $ms[0], $ms[intdiv(\count($ms), 2)], $ms[\count($ms) - 1]);
}

/** Time from `kill -9` of a holder to the next resume taking the lock. */
function kill9(string $lock, int $n): array
{
    $times = [];
    for ($i = 0; $i < $n; ++$i) {
        Harness::run(['ttl:30', 'setup', 'x']);
        $holder = new Harness([$lock, 'hold', 'k' . $i]);
        $holder->waitFor('HELD');
        $contender = new Harness([$lock, 'contend', 'k' . $i, '60']);
        $contender->waitFor('BLOCKED');
        $killedAt = $holder->kill9();
        $times[] = ((int) $contender->waitFor('ACQUIRED', 60)[1] - $killedAt) / 1e6;
    }

    return $times;
}

foreach (['getlock' => $runs, 'ttl:2' => $runs, 'ttl:30' => 3] as $lock => $n) {
    summary("kill -9 of the holder, then next resume ($lock)", kill9($lock, $n));
}

// Two workers, 200 slices each: overlapping slices are two holders at once.
foreach (['getlock', 'ttl:2'] as $lock) {
    Harness::run(['ttl:30', 'setup', 'x']);
    $workers = [new Harness([$lock, 'race', 'r', '200']), new Harness([$lock, 'race', 'r', '200'])];
    $slices = [];
    foreach ($workers as $worker) {
        foreach ($worker->events() as [$event, , $enter, $leave]) {
            'SLICE' === $event && $slices[] = [(int) $enter, (int) $leave];
        }
    }
    sort($slices);
    $overlaps = 0;
    for ($i = 1; $i < \count($slices); ++$i) {
        $overlaps += $slices[$i][0] < $slices[$i - 1][1] ? 1 : 0;
    }
    printf("%-58s %d slices, %d overlaps\n", "two workers contending ($lock)", \count($slices), $overlaps);
}

// GET_LOCK: the holder's connection is killed on the server, the adapter reconnects silently.
$times = [];
for ($i = 0; $i < $runs; ++$i) {
    $holder = new Harness(['getlock', 'watch', 'c' . $i]);
    $id = (int) $holder->waitFor('HELD')[2];
    $before = hrtime(true);
    $holder->pdo()->exec('KILL ' . $id);
    $times[] = ((int) $holder->waitFor('LOST', 10)[1] - $before) / 1e6;
}
summary('KILL <connection> of the holder, until holds() says no', $times);

// TTL row: a holder that outlives the TTL (no renewal) until holds() says no.
$times = [];
for ($i = 0; $i < $runs; ++$i) {
    $holder = new Harness(['ttl:2', 'watch', 'e' . $i]);
    $held = (int) $holder->waitFor('HELD')[1];
    $times[] = ((int) $holder->waitFor('LOST', 10)[1] - $held) / 1e6;
}
summary('TTL 2 s, holder alive and unrenewed, until holds() says no', $times);

// A frozen holder (SIGSTOP) keeps its connection open: the server sees no end of session.
foreach (['getlock', 'ttl:2'] as $lock) {
    Harness::run(['ttl:30', 'setup', 'x']);
    $holder = new Harness([$lock, 'hold', 'f']);
    $holder->waitFor('HELD');
    $contender = new Harness([$lock, 'contend', 'f', '8']);
    $contender->waitFor('BLOCKED');
    $frozenAt = $holder->freeze();
    $acquired = $contender->waitFor('ACQUIRED', 9);
    printf("%-58s %s\n", "frozen holder, next resume within 8 s ($lock)", $acquired ? \sprintf('%.0f ms', ((int) $acquired[1] - $frozenAt) / 1e6) : 'never');
}
