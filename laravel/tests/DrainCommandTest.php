<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * On the memory backend, `dispatchNewWorkflowRun()` only queues a run, and `durable:drain` drives
 * what the process has queued (#881, the user's decision of 2026-10-01). Each artisan call is its
 * own process with its own journal: this test checks the command is registered and runs.
 */
final class DrainCommandTest extends TestCase
{
    public function testTheMemoryBackendRegistersTheDrain(): void
    {
        [$exit, $output] = self::artisan('list', 'durable', '--raw');

        self::assertSame(0, $exit, $output);
        self::assertMatchesRegularExpression('/^durable:drain\b/m', $output);
    }

    public function testTheDrainRunsOnAnEmptyQueue(): void
    {
        [$exit, $output] = self::artisan('durable:drain');

        self::assertSame(0, $exit, $output);
    }

    /**
     * @return array{int, string}
     */
    private static function artisan(string ...$arguments): array
    {
        $process = proc_open(
            [\PHP_BINARY, 'artisan', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            \dirname(__DIR__),
            ['DURABLE_BACKEND' => 'memory', 'CACHE_STORE' => 'array'] + getenv(),
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
