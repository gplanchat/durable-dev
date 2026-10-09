<?php

declare(strict_types=1);

namespace integration\DurableModule\RunProjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #733: the run projection on Magento's adapter, against a real MySQL. A run that ends waits on
 * nothing, so `waiting_on` is cleared in the statement that sets the status (the DBAL projection
 * kept it, #851). The adapter loads in a child process (`Fixture/scenario.php`). Not run in CI
 * yet (#738); `DURABLE_TEST_MYSQL` is `user:password@host:port/database`.
 *
 * @internal
 */
final class TheWaitIsClearedWhenTheRunEndsTest extends TestCase
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

    /** @return iterable<string, array{string, string}> */
    public static function endings(): iterable
    {
        yield 'completed' => ['completed', 'completed'];
        yield 'failed' => ['failed', 'failed'];
        yield 'cancelled' => ['cancelled', 'cancelled'];
        yield 'continued as new' => ['continued_as_new', 'continued_as_new'];
    }

    #[Test]
    #[DataProvider('endings')]
    public function aRunThatEndsKeepsNoWait(string $scenario, string $status): void
    {
        $row = $this->row($scenario);

        self::assertSame($status, $row['status']);
        self::assertNotNull($row['ended_at']);
        self::assertNull($row['waiting_on']);
    }

    #[Test]
    public function aRunThatGoesOnKeepsItsLastWaitAndItsPickup(): void
    {
        $row = $this->row('going_on');

        self::assertSame('running', $row['status']);
        self::assertSame('activity spike.ship attempt 1 in flight', $row['waiting_on']);
        self::assertNotNull($row['picked_up_at']);
    }

    #[Test]
    public function aSecondStartRenamesTheRunAndKeepsItsStartAndItsFirstPickup(): void
    {
        $row = $this->row('twice');

        self::assertSame('App\\RenamedWorkflow', $row['workflow_type']);
        self::assertSame('2020-01-01 00:00:00.000', $row['started_at']);
        self::assertSame('2020-01-01 00:00:01.000', $row['picked_up_at']);
    }

    /** @return array<string, string|null> */
    private function row(string $scenario): array
    {
        $output = [];
        exec(\sprintf('%s %s %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg(__DIR__ . '/Fixture/scenario.php'), escapeshellarg($scenario)), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        /** @var array<string, string|null> */
        return json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
    }
}
