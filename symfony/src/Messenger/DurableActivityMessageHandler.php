<?php

declare(strict_types=1);

namespace App\Messenger;

use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Failure\ActivityFailureEventFactory;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Traite les {@see ActivityMessage} publiés sur le transport Messenger (équivalent du worker
 * {@see \Gplanchat\Durable\Bundle\Command\ActivityWorkerCommand} en mode long-running).
 */
#[AsMessageHandler(fromTransport: 'durable_activities')]
final class DurableActivityMessageHandler
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityExecutor $activityExecutor,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly int $maxActivityRetries = 0,
    ) {
    }

    public function __invoke(ActivityMessage $message): void
    {
        try {
            $result = $this->activityExecutor->execute($message->activityName, $message->payload);
            $this->eventStore->append(new ActivityCompleted(
                $message->executionId,
                $message->activityId,
                $result,
            ));
            $this->resumeDispatcher->dispatchResume($message->executionId);
        } catch (\Throwable $e) {
            if ($message->attempt() <= $this->maxActivityRetries) {
                $this->activityTransport->enqueue($message->withAttempt($message->attempt() + 1));
            } else {
                $this->eventStore->append(ActivityFailureEventFactory::fromActivityThrowable(
                    $message->executionId,
                    $message->activityId,
                    $message->activityName,
                    $message->attempt(),
                    $e,
                ));
                $this->resumeDispatcher->dispatchResume($message->executionId);
            }
        }
    }
}
