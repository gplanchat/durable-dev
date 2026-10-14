<?php

declare(strict_types=1);

namespace App\Tests\Integration\Client;

use App\Durable\ApprovalRepository;
use App\Durable\DurableMessengerDrain;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One workflow driven through a declared repository on the in-memory backend: start, query,
 * signal, query, result. The in-memory client queues each operation on Messenger, so the test
 * drains the transports between steps, as a worker would.
 *
 * @internal
 */
#[Group('integration')]
final class ApprovalRepositoryTest extends KernelTestCase
{
    public function testStartQuerySignalQueryResult(): void
    {
        self::bootKernel();
        $id = ExecutionId::generate();
        $stub = self::getContainer()->get(ApprovalRepository::class)->create($id)->start(document: 'invoice-42');

        self::assertSame('signal_wait', $this->drainUntilCompleteOrSignalWait($id));
        self::assertSame('pending', $stub->status());

        $stub->approve();
        self::assertTrue($this->drainUntilSettled($id));

        self::assertSame('approved', $stub->status());
        self::assertSame('invoice-42 approved', $stub->result());
    }

    private function drainUntilCompleteOrSignalWait(ExecutionId $id): string
    {
        $c = self::getContainer();

        return DurableMessengerDrain::drainUntilCompleteOrSignalWait(
            $c->get(EventStoreInterface::class),
            $c->get(WorkflowMetadataStore::class),
            $c->get(MessageBusInterface::class),
            $c->get('messenger.receiver_locator'),
            $id->toString(),
        );
    }

    private function drainUntilSettled(ExecutionId $id): bool
    {
        $c = self::getContainer();

        return DurableMessengerDrain::drainUntilWorkflowSettled(
            $c->get(EventStoreInterface::class),
            $c->get(WorkflowMetadataStore::class),
            $c->get(MessageBusInterface::class),
            $c->get('messenger.receiver_locator'),
            $id->toString(),
        );
    }
}
