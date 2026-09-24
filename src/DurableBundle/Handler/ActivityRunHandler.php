<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Consumes {@see ActivityMessage} through Symfony Messenger (configured activities transport).
 */
final class ActivityRunHandler
{
    public function __construct(
        private readonly ActivityMessageProcessor $activityMessageProcessor,
    ) {}

    public function __invoke(ActivityMessage $message): void
    {
        // The core decided this failure is not worth another attempt, and journalled it: Messenger
        // must not retry it either, and its failure transport is where an operator looks (#341).
        // A retry from there is answered by the journal, not run again.
        $failure = $this->activityMessageProcessor->process($message);
        if (null !== $failure) {
            throw new UnrecoverableMessageHandlingException($failure->getMessage(), 0, $failure);
        }
    }
}
