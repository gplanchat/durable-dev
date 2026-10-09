<?php

declare(strict_types=1);

/*
 * One activity-attempt claimant, run by AttemptClaimTest as its own process.
 *
 *   php claim-worker.php <hold|watch|contend|release> <execution> <activity> <attempt>
 *
 * One line per event, `hrtime(true)` first, as in worker.php.
 */

use Gplanchat\Durable\ExecutionId;
use Gplanchat\DurableModule\Runtime\MagentoActivityAttemptClaim;
use Gplanchat\DurableModule\Runtime\ResumeLock\GetLockResumeLock;

require __DIR__ . '/bootstrap.php';

[, $mode, $execution, $activity, $attempt] = $argv;
$execution = ExecutionId::fromString($execution);
$connection = durable_test_connection();
$claim = new MagentoActivityAttemptClaim(new GetLockResumeLock($connection));

function say(string $event, int|string ...$values): void
{
    echo implode(' ', [$event, hrtime(true), ...$values]), "\n";
    flush();
}

$release = $claim->claim($execution, $activity, (int) $attempt);

switch ($mode) {
    case 'contend':
        null === $release ? say('DEFERRED') : say('CLAIMED');
        break;
    case 'release':
        null === $release && exit(1);
        $release();
        say('RELEASED');
        break;
    case 'hold':
    case 'watch':
        null === $release && exit(1);
        say('HELD', (int) $connection->fetchOne('SELECT CONNECTION_ID()'));
        while ('hold' === $mode || $claim->holds($execution, $activity, (int) $attempt)) {
            usleep(20_000);
        }
        say('LOST');
        break;
}
