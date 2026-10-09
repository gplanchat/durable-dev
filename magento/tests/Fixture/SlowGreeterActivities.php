<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench\Fixture;

/**
 * Its first run, in whichever process, writes `<DURABLE_BENCH_DIR>/started` and hangs for a minute
 * (the test kills that process); a later run answers at once. `runs` has one line per run.
 */
final class SlowGreeterActivities implements SlowGreeter
{
    public function greet(string $name): string
    {
        $dir = (string) getenv('DURABLE_BENCH_DIR');
        file_put_contents($dir . '/runs', getmypid() . "\n", \FILE_APPEND);
        if (1 === \count(file($dir . '/runs'))) {
            touch($dir . '/started');
            sleep(60);
        }

        return 'Hello, ' . $name;
    }
}
