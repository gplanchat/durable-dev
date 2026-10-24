<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Observation\Wait;

/**
 * Thrown when the workflow must stop and be re-dispatched
 * (distributed mode, activity pending).
 *
 * {@see shouldDispatchResume()}: false for signals / updates — only the
 * {@see \Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler} handlers (etc.) must
 * relaunch; otherwise a **sync** Messenger transport loops forever.
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
final class WorkflowSuspendedException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $shouldDispatchResume = true,
        private readonly bool $waitingOnTimer = false,
        /**
         * What the wait bears on, when that has a name: without it, a runner that observes an
         * execution no longer moving forward can only say "stuck", never "stuck on that
         * particular condition". Held in its stored form ({@see \Gplanchat\Durable\Observation\Wait}).
         */
        private readonly ?string $waitingOn = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * If true, {@see \Gplanchat\Durable\Handler\ResumeWorkflowHandler} sends a {@see \Gplanchat\Durable\Transport\ResumeWorkflowMessage} resume
     * (activity / timer to be moved forward by a worker).
     */
    public function shouldDispatchResume(): bool
    {
        return $this->shouldDispatchResume;
    }

    /**
     * If true, the wait bears on a durable timer: do not chain immediate resumes;
     * schedule {@see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage} (possibly with a Messenger delay).
     */
    public function waitingOn(): ?string
    {
        return null === $this->waitingOn ? null : Wait::fromStorage($this->waitingOn)->toString();
    }

    /** What a run catalog records: the kind and its parameters, so a dashboard can word it itself (#850). */
    public function storedWait(): ?string
    {
        return $this->waitingOn;
    }

    public function waitingOnTimer(): bool
    {
        return $this->waitingOnTimer;
    }
}
