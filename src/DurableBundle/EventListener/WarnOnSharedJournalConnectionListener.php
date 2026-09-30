<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * The DBAL journal is on the application's default connection (DUR054). Registered by
 * {@see \Gplanchat\Durable\Bundle\DependencyInjection\Compiler\WarnOnSharedJournalConnectionPass}
 * only when it is, and heard when a worker starts: that is where the journal is written, and where
 * the operator reads the logs. A warning, not a refusal: the worker runs as it would have.
 */
final readonly class WarnOnSharedJournalConnectionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $connection,
    ) {}

    public function __invoke(WorkerStartedEvent $event): void
    {
        $this->logger->warning(\sprintf(
            'Durable: the journal is on the application\'s default connection "%s". Durable\'s '
            . 'transactions then nest inside business ones, and a business rollback erases journal '
            . 'events. Give it a DBAL connection of its own and name it in durable.dbal.connection (DUR054).',
            $this->connection,
        ));
    }
}
