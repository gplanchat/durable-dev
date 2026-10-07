<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\ActivityAttemptDeferred;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An activity, on the queue the application already drains.
 *
 * The job does nothing itself: it carries the message and hands it back to the core's processor,
 * the very one the Messenger handler of the Symfony bundle calls. The timeouts, the journal, the
 * workflow resume and the retry policy live there, once, for every host.
 *
 * **No queue trait.** `Queueable` and `InteractsWithQueue` serve `dispatch()` and `release()`; this
 * job is pushed by the transport and never puts itself back on the queue. The resume job, for its
 * part, will need them: that is where the package will take `illuminate/queue`.
 */
final readonly class RunActivityJob implements ShouldQueue
{
    public function __construct(
        public readonly ActivityMessage $message,
    ) {}

    public function handle(ActivityMessageProcessor $processor, ActivityTransportInterface $activities): void
    {
        try {
            $processor->process($this->message);
        } catch (ActivityAttemptDeferred) {
            // Another worker holds the attempt: the same attempt goes back on the queue for later,
            // as a fresh job, so it neither spends `tries` nor gets dropped (#590). Unbounded on
            // purpose: the holder either journals the attempt or dies, and its claim expires.
            $activities->enqueue($this->message->deferredBy(Duration::seconds((float) ActivityAttemptDeferred::RETRY_AFTER_SECONDS)));
        }
    }
}
