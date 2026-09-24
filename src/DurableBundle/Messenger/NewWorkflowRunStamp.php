<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Marks the {@see \Gplanchat\Durable\Transport\ResumeWorkflowMessage} that starts a run, with the type
 * the message itself does not carry: the profiler records a new run, not a resume (#337).
 */
final readonly class NewWorkflowRunStamp implements StampInterface
{
    public function __construct(
        public string $workflowType,
    ) {}
}
