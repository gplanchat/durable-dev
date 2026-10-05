<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\PassFence;
use Gplanchat\DurableModule\Store\MagentoEventStore;
use PHPUnit\Framework\TestCase;

/**
 * DUR053 on the Magento adapter (#749): a superseded pass writes nothing, also when the two passes
 * are separate processes, and the fence keeps the event store's one-transaction rule.
 */
final class MagentoEventStoreFenceTest extends TestCase
{
    public function testTheOlderPassWritesNoEventOnceTheNewerOneClaims(): void
    {
        $store = new MagentoEventStore(JournalHarness::adapter());
        $id = ExecutionId::fromString('exec-two-passes');
        $first = $store->claimPass($id);
        $second = $store->claimPass($id);

        self::assertGreaterThan($first->epoch, $second->epoch);

        try {
            $store->appendFenced(new ExecutionStarted($id), $first);
            self::fail('the older pass must be refused');
        } catch (SupersededPassException) {
        }

        self::assertSame(0, $store->countEventsInStream($id));
        $store->appendFenced(new ExecutionStarted($id), $second);
        self::assertSame(1, $store->countEventsInStream($id));
    }

    public function testNoFenceIsAPlainAppend(): void
    {
        $store = new MagentoEventStore(JournalHarness::adapter());
        $id = ExecutionId::fromString('exec-no-fence');

        $store->appendFenced(new ExecutionStarted($id), PassFence::none($id->toString()));

        self::assertSame(1, $store->countEventsInStream($id));
    }

    public function testAClaimOrAFencedAppendInsideAnOpenTransactionThrowsAndLeavesItOpen(): void
    {
        $adapter = JournalHarness::adapter();
        $store = new MagentoEventStore($adapter);
        $id = ExecutionId::fromString('exec-fence-nested');
        $fence = $store->claimPass($id);

        $adapter->beginTransaction();

        try {
            foreach ([
                static fn() => $store->claimPass($id),
                static fn() => $store->appendFenced(new ExecutionStarted($id), $fence),
            ] as $call) {
                try {
                    $call();
                    self::fail('must refuse to run inside an open transaction');
                } catch (\RuntimeException $e) {
                    self::assertStringContainsString('transaction', $e->getMessage());
                }
            }
            self::assertSame(1, $adapter->getTransactionLevel());
        } finally {
            $adapter->rollBack();
        }

        self::assertSame(0, $store->countEventsInStream($id));
    }

    /** Two processes each claim the same execution, wait for each other's claim, then both append. */
    public function testOfTwoProcessesRacingForOneStreamOnlyTheNewestClaimAppends(): void
    {
        $store = new MagentoEventStore(JournalHarness::adapter());

        for ($round = 1; $round <= 5; ++$round) {
            $id = 'exec-race-' . $round;
            $dir = sys_get_temp_dir() . '/durable-fence-' . bin2hex(random_bytes(4));
            mkdir($dir);
            $procs = [];
            $pipes = [];
            foreach (['a', 'b'] as $name) {
                $procs[$name] = proc_open(
                    [\PHP_BINARY, __DIR__ . '/fenced_pass.php', $dir, $name, $id],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes[$name],
                );
            }
            $results = [];
            foreach ($procs as $name => $proc) {
                $out = stream_get_contents($pipes[$name][1]) . stream_get_contents($pipes[$name][2]);
                self::assertSame(0, proc_close($proc), $out);
                $results[$name] = trim($out);
            }

            $epochs = ['a' => (int) file_get_contents($dir . '/a.epoch'), 'b' => (int) file_get_contents($dir . '/b.epoch')];
            $winner = $epochs['a'] > $epochs['b'] ? 'a' : 'b';
            self::assertSame('appended', $results[$winner], 'round ' . $round);
            self::assertSame('superseded', $results['a' === $winner ? 'b' : 'a'], 'round ' . $round);
            self::assertSame(1, $store->countEventsInStream(ExecutionId::fromString($id)), 'round ' . $round);
        }
    }
}
