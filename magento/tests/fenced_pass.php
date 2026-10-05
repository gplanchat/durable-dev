<?php

declare(strict_types=1);

/*
 * One racing pass of MagentoEventStoreFenceTest, run as its own process: claim, wait until the
 * other pass has claimed too, then try one fenced append. Usage: fenced_pass.php <dir> <name> <execution>
 * It prints "appended" or "superseded". It does not reset the journal: the test owns it.
 */

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Gplanchat\DurableModule\Store\MagentoEventStore;
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/bootstrap.php';

[, $dir, $name, $execution] = $argv;
$other = 'a' === $name ? 'b' : 'a';

$adapter = Bootstrap::create(BP, $_SERVER)->getObjectManager()->get(JournalConnectionResolver::class)->resolve();
$store = new MagentoEventStore($adapter);
$id = ExecutionId::fromString($execution);

$fence = $store->claimPass($id);
file_put_contents($dir . '/' . $name . '.epoch', (string) $fence->epoch);
touch($dir . '/' . $name . '.claimed');

$deadline = microtime(true) + 30;
while (!is_file($dir . '/' . $other . '.claimed')) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, 'the other pass never claimed');
        exit(2);
    }
    usleep(1000);
}

try {
    $store->appendFenced(new ExecutionStarted($id, ['by' => $name]), $fence);
    echo 'appended';
} catch (SupersededPassException) {
    echo 'superseded';
}
