<?php

declare(strict_types=1);

namespace integration\DurableModule\ClientPort;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #973, #974, #976: one workflow driven through a repository on the database backend, end to end.
 * The application is one process (`Fixture/client.php`), the worker another (`Fixture/worker.php`),
 * and the two meet only in the table queue and the journal. Magento's adapter loads in the
 * children, never here. `DURABLE_TEST_MYSQL` is `user:password@host:port/database`.
 *
 * Nothing here measures time. The query after the signal reads the journal the signal has just
 * written, and the result waits on the worker under a 60 s bound that only ends a run whose
 * worker never answers.
 *
 * @internal
 */
final class TheClientPortDrivesAWorkflowThroughARepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_file(\dirname(__DIR__, 4) . '/magento/vendor/composer/autoload_psr4.php')) {
            self::markTestSkipped('magento/vendor is not installed');
        }
        if (!getenv('DURABLE_TEST_MYSQL')) {
            self::markTestSkipped('DURABLE_TEST_MYSQL (user:password@host:port/database) names no MySQL server');
        }
    }

    #[Test]
    public function aWorkflowIsStartedSignalledQueriedAndAnsweredThroughItsRepository(): void
    {
        $this->client(['reset']);
        $stop = sys_get_temp_dir() . '/' . uniqid('durable-client-stop-', true);
        $errors = $stop . '.stderr';
        $worker = proc_open(
            [\PHP_BINARY, '-d', 'memory_limit=256M', __DIR__ . '/Fixture/worker.php', $stop],
            [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
        );
        self::assertIsResource($worker);

        try {
            // The worker prints READY once its schema is in place; the client never races it on DDL.
            self::assertSame("READY\n", fgets($pipes[1]), 'the worker never got ready: ' . (string) file_get_contents($errors));
            $run = $this->client(['drive', 'client-port-' . bin2hex(random_bytes(4))]);
        } finally {
            touch($stop);
            $output = (string) stream_get_contents($pipes[1]);
            $exit = proc_close($worker);
            $stderr = (string) file_get_contents($errors);
            array_map('unlink', array_filter([$stop, $errors], 'is_file'));
        }

        self::assertSame(0, $exit, $stderr);
        self::assertSame('', $stderr, 'the worker wrote to stderr');
        self::assertMatchesRegularExpression('/^HANDLED [1-9]\d*$/m', $output, 'the worker ran at least one message');
        self::assertSame('waiting', $run['before'], 'a query before the signal');
        self::assertSame('paid', $run['after'], 'a query after the signal');
        self::assertSame('paid:ada', $run['result'], 'the result the worker journalled');
        $journal = \sprintf('metadata completed: %s; ', var_export($run['completed'], true)) . 'journal: ' . implode(', ', array_map(static fn(string $class): string => substr($class, (int) strrpos($class, '\\') + 1), $run['events']));
        self::assertSame(1, \count(array_keys($run['events'], WorkflowSignalReceived::class, true)), $journal);
        self::assertSame(1, \count(array_keys($run['events'], ExecutionCompleted::class, true)), $journal);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<string, mixed>
     */
    private function client(array $arguments): array
    {
        $output = [];
        exec(\sprintf('%s %s %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg(__DIR__ . '/Fixture/client.php'), implode(' ', array_map('escapeshellarg', $arguments))), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        /** @var array<string, mixed> */
        return json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
    }
}
