<?php

declare(strict_types=1);

namespace integration\Temporal;

use PHPUnit\Framework\TestCase;

/**
 * A Magento module serves Nexus operations on a real server (#668): a handler listed in
 * `nexusHandlers` answers `billing/verify`, and a module workflow fulfils `billing/charge`. The
 * root's demo harness (#663) routes `billing` to the module's queue and calls it.
 */
final class MagentoServesNexusTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $i => $process) {
            // The harness first, with SIGTERM, so it deletes its endpoints; the hosts hold nothing.
            proc_terminate($process, 0 === $i ? \SIGTERM : \SIGKILL);
            proc_close($process);
        }
    }

    public function testAListedHandlerAnswersAndAModuleWorkflowFulfilsTheRest(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }
        $namespace = getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test';
        $transport = TemporalServerTestCase::transportFromEnv();
        $prefix = 'mg' . bin2hex(random_bytes(3)) . '-';
        $harness = [__DIR__ . '/nexus-demo-harness.php', $address, $namespace, '--prefix=' . $prefix, '--transport=' . $transport];

        $log = $this->spawn([...$harness, '--bench=billing@' . $prefix . 'magento-nexus']);
        $deadline = microtime(true) + 60.0;
        while (1 !== preg_match('/^ready$/m', (string) file_get_contents($log))) {
            self::assertLessThan($deadline, microtime(true), "The demo harness never said ready:\n" . file_get_contents($log));
            usleep(200_000);
        }
        $dsn = \sprintf('temporal://%s?namespace=%s&nexus_task_queue=%smagento-nexus&workflow_task_queue=%smagento-workflows&transport=%s', $address, $namespace, $prefix, $prefix, $transport);
        foreach (['nexus', 'journal'] as $role) {
            $this->spawn([__DIR__ . '/Hosts/magento-nexus.php', $dsn, $role]);
        }

        // The module's handler refuses what the harness would accept.
        self::assertSame(['accepted' => false, 'reason' => 'EUR only, on this module'], $this->call($harness, 'billing/verify', ['order' => 'ORD-1', 'amount' => 1200, 'currency' => 'USD']));
        // The module's workflow, not the harness's RCP-…, answers the charge.
        self::assertSame(['receipt' => 'MAGENTO-EUR-ORD-2', 'charged' => 1200], $this->call($harness, 'billing/charge', ['order' => 'ORD-2', 'amount' => 1200, 'currency' => 'EUR']));
    }

    /**
     * @param list<string>         $harness
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function call(array $harness, string $operation, array $payload): array
    {
        // stderr to a file: reading one pipe to its end before the other would block a child
        // that fills the second.
        $errors = (string) tempnam(sys_get_temp_dir(), 'durable-magento-call-');
        $process = proc_open([\PHP_BINARY, ...$harness, '--call=' . $operation, '--input=' . json_encode($payload, \JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        self::assertSame(0, proc_close($process), $output . file_get_contents($errors));

        return json_decode((string) strrchr(trim($output), "\n") ?: $output, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $command
     */
    private function spawn(array $command): string
    {
        $log = tempnam(sys_get_temp_dir(), 'durable-magento-nexus-');
        self::assertIsString($log);
        $process = proc_open([\PHP_BINARY, ...$command], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;

        return $log;
    }
}
