<?php

declare(strict_types=1);

namespace integration\DurableModule\ResumeLock;

/**
 * Runs `Fixture/worker.php` processes against a private MySQL, named by `DURABLE_TEST_MYSQL`
 * (`user:password@host:port/database`), and reads their events. Magento's adapter loads in the
 * worker, never in the test process.
 *
 * @internal
 */
final class Harness
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    private int $pid;

    /** @var list<list<string>> */
    public array $events = [];

    /** @param list<string> $arguments */
    public function __construct(array $arguments)
    {
        $this->process = proc_open(
            [\PHP_BINARY, '-d', 'memory_limit=256M', __DIR__ . '/Fixture/worker.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']],
            $pipes,
        ) ?: throw new \RuntimeException('cannot start a worker');
        $this->stdout = $pipes[1];
        $this->pid = proc_get_status($this->process)['pid'];
    }

    public static function unavailable(): ?string
    {
        return match (true) {
            !\function_exists('posix_kill') => 'the lock tests kill a process: posix is missing',
            !is_file(\dirname(__DIR__, 4) . '/magento/vendor/composer/autoload_psr4.php') => 'magento/vendor is not installed',
            !getenv('DURABLE_TEST_MYSQL') => 'DURABLE_TEST_MYSQL (user:password@host:port/database) names no MySQL server',
            default => null,
        };
    }

    /** @param list<string> $arguments */
    public static function run(array $arguments): void
    {
        $worker = new self($arguments);
        $worker->events();
    }

    public function pdo(): \PDO
    {
        $dsn = parse_url('mysql://' . getenv('DURABLE_TEST_MYSQL'));

        return new \PDO(
            \sprintf('mysql:host=%s;port=%d;dbname=%s', $dsn['host'] ?? '', $dsn['port'] ?? 3306, ltrim($dsn['path'] ?? '', '/')),
            $dsn['user'] ?? '',
            $dsn['pass'] ?? '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * Waits for an event and returns its fields, the first being the worker's `hrtime(true)`.
     *
     * @return list<string>|null null when the timeout passes or the worker exits first
     */
    public function waitFor(string $event, float $seconds = 10): ?array
    {
        foreach ($this->events as $known) {
            if ($event === $known[0]) {
                return $known;
            }
        }
        for ($until = microtime(true) + $seconds; ($left = $until - microtime(true)) > 0;) {
            $read = [$this->stdout];
            $write = $except = null;
            if (!stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1e6))) {
                break;
            }
            $line = fgets($this->stdout);
            if (false === $line) {
                break;
            }
            $this->events[] = $fields = explode(' ', trim($line));
            if ($event === $fields[0]) {
                return $fields;
            }
        }

        return null;
    }

    /** @return list<list<string>> every event, once the worker has exited */
    public function events(): array
    {
        while (false !== ($line = fgets($this->stdout))) {
            $this->events[] = explode(' ', trim($line));
        }
        proc_close($this->process);

        return $this->events;
    }

    /** @return int hrtime(true) taken just before the signal */
    public function kill9(): int
    {
        $before = hrtime(true);
        posix_kill($this->pid, 9);
        proc_close($this->process);

        return $before;
    }

    /** Freezes the worker with its connection open: a partitioned or hung host sends no FIN. */
    public function freeze(): int
    {
        $before = hrtime(true);
        posix_kill($this->pid, 19);

        return $before;
    }

    public function __destruct()
    {
        if (\is_resource($this->process)) {
            posix_kill($this->pid, 9);
            proc_close($this->process);
        }
    }
}
