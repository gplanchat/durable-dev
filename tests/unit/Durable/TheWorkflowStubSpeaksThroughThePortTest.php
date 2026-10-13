<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Stub\WorkflowStub;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\TestCase;

#[AsWorkflow('stub-test')]
final class StubPortTestWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $orderId, int $retries = 3): void {}

    #[AsSignalMethod('add')]
    public function addLine(string $sku, int $quantity = 1): void {}

    #[AsQueryMethod('total')]
    public function total(): int
    {
        return 0;
    }

    #[AsUpdateMethod('rename')]
    public function rename(string $label): string
    {
        return $label;
    }
}

/**
 * Each stub verb is one call on the client port, with the arguments mapped by parameter name.
 */
final class TheWorkflowStubSpeaksThroughThePortTest extends TestCase
{
    /** @var list<array{string, array<int, mixed>}> */
    private array $calls = [];

    /** @return WorkflowStub<StubPortTestWorkflow> */
    private function stub(?WorkflowStartOptions $options = null): WorkflowStub
    {
        $port = new class ($this->calls) implements WorkflowClientPort {
            /** @param list<array{string, array<int, mixed>}> $calls */
            public function __construct(private array &$calls) {}

            public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
            {
                $this->calls[] = ['start', [$workflowType, $input, $options]];

                return $executionId;
            }

            public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void
            {
                $this->calls[] = ['signal', [$name, $arguments]];
            }

            public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
            {
                $this->calls[] = ['query', [$name, $arguments]];

                return 42;
            }

            public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
            {
                $this->calls[] = ['update', [$name, $arguments]];

                return 'renamed';
            }

            public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
            {
                $this->calls[] = ['result', []];

                return 'done';
            }

            public function cancel(ExecutionId $executionId, ?string $requestId = null): void
            {
                $this->calls[] = ['cancel', []];
            }

            public function terminate(ExecutionId $executionId, string $reason = ''): void
            {
                $this->calls[] = ['terminate', [$reason]];
            }
        };

        return new WorkflowStub(ExecutionId::fromString('s-1'), $port, StubPortTestWorkflow::class, false, $options);
    }

    public function testExecuteStartsThenWaitsForTheResult(): void
    {
        $options = new WorkflowStartOptions();

        self::assertSame('done', $this->stub($options)->execute('o-1'));
        self::assertSame(['start', ['stub-test', ['orderId' => 'o-1', 'retries' => 3], $options]], $this->calls[0]);
        self::assertSame('result', $this->calls[1][0]);
    }

    public function testSignalQueryAndUpdateTravelByParameterNameUnderTheirAttributeName(): void
    {
        $stub = $this->stub();

        $stub->addLine('sku-1', quantity: 2); // @phpstan-ignore method.notFound (typed by the PHPStan extension, a follow-up)
        self::assertSame(42, $stub->total()); // @phpstan-ignore method.notFound (typed by the PHPStan extension, a follow-up)
        self::assertSame('renamed', $stub->RENAME('new')); // @phpstan-ignore method.notFound (typed by the PHPStan extension, a follow-up)

        self::assertSame([
            ['signal', ['add', ['sku' => 'sku-1', 'quantity' => 2]]],
            ['query', ['total', []]],
            ['update', ['rename', ['label' => 'new']]],
        ], $this->calls);
    }
}
