<?php

declare(strict_types=1);

namespace integration\Durable\Crash;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The measurement the product's central promise has never had: an execution survives the death of
 * the process that started it.
 *
 * Every other bench in this repository runs in one process. The in-memory runner replays for real —
 * that part is honest — but a single process cannot show that nothing important was living in its
 * memory rather than in the journal. A leaked container, a captured closure, a static counter: all
 * of them survive a replay and none of them survives a `SIGKILL`, and until now nothing here has
 * ever asked them to.
 *
 * This is the **bare** bench, without an agent. It answers one question and refuses the next one:
 * if it is green, a failure of the same shape with the agent maquette lives in the maquette; if it
 * is red, it lives in the core, and everything built on top of "the execution survives" is built on
 * a guess.
 *
 * @internal
 */
final class InterprocessCrashBenchTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!\function_exists('posix_kill') || !\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('the bench needs posix and pdo_sqlite: it kills a process and shares a journal file');
        }

        $this->directory = sys_get_temp_dir() . '/durable-crash-bench-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    #[Test]
    public function anExecutionKilledBetweenTwoActivitiesResumesInAnotherProcessWithoutRepayingTheFirst(): void
    {
        $journal = $this->directory . '/journal.sqlite';
        $log = $this->directory . '/activities.log';
        $executionId = '01900000-0000-7000-8000-0000000000c1';

        $crashed = $this->runSlice($journal, $executionId, 'start', $log, kill: true);

        // A process the kernel killed outright never gets to report an exit code of its own; what
        // matters is only that it is not zero, because zero would mean it finished the workflow and
        // there is nothing left to resume.
        self::assertNotSame(0, $crashed['code'], 'the first process must die, not finish');
        self::assertSame(
            ['bench.first'],
            $this->activitiesRun($log),
            'the first process runs the first activity and dies before scheduling the second',
        );

        $resumed = $this->runSlice($journal, $executionId, 'resume', $log);

        self::assertSame(0, $resumed['code'], 'the second process must carry the execution to its end: ' . $resumed['stderr']);
        self::assertSame(
            ['first' => 'first:a', 'second' => 'second:b'],
            json_decode(trim($resumed['stdout']), true, 512, JSON_THROW_ON_ERROR),
            'the resumed execution returns what an uninterrupted one would have returned',
        );

        // The whole bench is this one line. `bench.first` appears once and only once: it was run by
        // a process that no longer exists, and the process that finished the job was served its
        // result out of the journal instead of paying for it a second time. Anything the first
        // process had kept in memory rather than in the journal is gone, and the execution did not
        // need it.
        self::assertSame(
            ['bench.first', 'bench.second'],
            $this->activitiesRun($log),
            'the first activity is served from the journal, never re-executed',
        );
    }

    /**
     * @return list<string>
     */
    private function activitiesRun(string $log): array
    {
        return array_values(array_filter(explode("\n", (string) @file_get_contents($log))));
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runSlice(string $journal, string $executionId, string $phase, string $log, bool $kill = false): array
    {
        $environment = ['BENCH_LOG' => $log] + ($kill ? ['BENCH_KILL' => '1'] : []);

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/bench_slice.php', $journal, $executionId, $phase],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );

        self::assertIsResource($process, 'the bench must be able to spawn a second PHP process');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
