<?php

declare(strict_types=1);

namespace integration\DurableModule\TableQueue;

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

    /** @param list<string> $arguments */
    public function __construct(array $arguments)
    {
        $this->process = proc_open(
            [\PHP_BINARY, '-d', 'memory_limit=256M', __DIR__ . '/Fixture/worker.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']],
            $pipes,
        ) ?: throw new \RuntimeException('cannot start a worker');
        $this->stdout = $pipes[1];
    }

    public static function unavailable(): ?string
    {
        return match (true) {
            !is_file(\dirname(__DIR__, 4) . '/magento/vendor/composer/autoload_psr4.php') => 'magento/vendor is not installed',
            !getenv('DURABLE_TEST_MYSQL') => 'DURABLE_TEST_MYSQL (user:password@host:port/database) names no MySQL server',
            default => null,
        };
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<list<string>>
     */
    public static function run(array $arguments): array
    {
        return (new self($arguments))->events();
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

    /** @return list<list<string>> every event, once the worker has exited */
    public function events(): array
    {
        $events = [];
        while (false !== ($line = fgets($this->stdout))) {
            $events[] = explode(' ', trim($line));
        }
        proc_close($this->process);

        return $events;
    }

    /** @return list<string>|null the first TAKEN event, as soon as it is printed */
    public function waitForTaken(): ?array
    {
        while (false !== ($line = fgets($this->stdout))) {
            if (str_starts_with($line, 'TAKEN')) {
                return explode(' ', trim($line));
            }
        }

        return null;
    }

    public function __destruct()
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
    }
}
