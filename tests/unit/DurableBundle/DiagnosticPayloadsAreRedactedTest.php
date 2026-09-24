<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The journal holds what a workflow was given, credentials included. The profiler panel and
 * `durable:execution:diagnose` copy it somewhere with a wider audience, so they mask it (#335).
 */
final class DiagnosticPayloadsAreRedactedTest extends TestCase
{
    private const SECRET = 'hunter2';

    public function testTheProfilerPanelMasksAPassword(): void
    {
        $trace = new DurableExecutionTrace();
        $trace->onWorkflowDispatchRequested('exec-1', 'Signup', ['password' => self::SECRET], false, 'async');
        [$metadata, $events] = $this->storesWithASecret();

        $collector = new DurableDataCollector($trace, $metadata, $events);
        $collector->collect(new Request(), new Response());

        $stored = serialize($collector);
        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertStringContainsString('ada@example.com', $stored, 'the rest of the payload is what the operator came to read');
    }

    public function testAnObjectInThePayloadIsMaskedToo(): void
    {
        $metadata = new InMemoryWorkflowMetadataStore();
        $metadata->save('exec-1', 'Signup', ['user' => new SignupCredentials('ada', self::SECRET)]);
        $trace = new DurableExecutionTrace();
        $trace->onWorkflowDispatchRequested('exec-1', 'Signup', [], false, 'async');

        $collector = new DurableDataCollector($trace, $metadata, new InMemoryEventStore());
        $collector->collect(new Request(), new Response());
        $tester = new CommandTester(new DiagnoseExecutionCommand($metadata, new InMemoryEventStore(), new InMemoryChildWorkflowParentLinkStore()));
        $tester->execute(['executionId' => 'exec-1', '--json' => true]);

        self::assertStringNotContainsString(self::SECRET, serialize($collector));
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
    }

    public function testAnApplicationRedactorIsNotOverwritten(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->register('app.redactor', \stdClass::class);
        $container->setAlias(PayloadRedactorInterface::class, 'app.redactor');

        (new DurableExtension())->load([[]], $container);

        self::assertSame('app.redactor', (string) $container->getAlias(PayloadRedactorInterface::class));
    }

    public function testTheJsonDiagnosisMasksAPassword(): void
    {
        $output = $this->diagnose(['--json' => true]);

        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertStringContainsString('ada@example.com', $output);
    }

    public function testRawAsksForTheSecretExplicitly(): void
    {
        self::assertStringContainsString(self::SECRET, $this->diagnose(['--json' => true, '--raw' => true]));
    }

    public function testTheTextDiagnosisMasksAPasswordToo(): void
    {
        self::assertStringNotContainsString(self::SECRET, $this->diagnose([]));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function diagnose(array $options): string
    {
        [$metadata, $events] = $this->storesWithASecret();
        $tester = new CommandTester(new DiagnoseExecutionCommand($metadata, $events, new InMemoryChildWorkflowParentLinkStore()));
        $tester->execute(['executionId' => 'exec-1'] + $options);

        return $tester->getDisplay();
    }

    /**
     * @return array{InMemoryWorkflowMetadataStore, InMemoryEventStore}
     */
    private function storesWithASecret(): array
    {
        $metadata = new InMemoryWorkflowMetadataStore();
        $metadata->save('exec-1', 'Signup', ['email' => 'ada@example.com', 'password' => self::SECRET]);
        $events = new InMemoryEventStore();
        $events->append(new ActivityScheduled('exec-1', 'act-1', 'createAccount', ['email' => 'ada@example.com', 'password' => self::SECRET]));

        return [$metadata, $events];
    }
}

/**
 * A readonly DTO at the boundary, as the codebase favours: the in-memory store keeps it as is.
 */
final class SignupCredentials implements \JsonSerializable
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {}

    public function jsonSerialize(): array
    {
        return ['username' => $this->username, 'password' => $this->password];
    }
}
