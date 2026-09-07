<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;

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
        $this->activityMessageProcessor->process($message);
    }
}
