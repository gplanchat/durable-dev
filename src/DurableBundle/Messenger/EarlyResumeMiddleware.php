<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\Exception\ResumeArrivedBeforeItsOutcome;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * What an early resume becomes on Messenger (#606).
 *
 * Under DUR050 and DUR052 a worker sends a resume before it appends the fact it announces (an
 * activity's outcome, a signal, a child's outcome, a fired timer), and another after. The early one
 * that finds its fact missing waits by failing, and the transport's retries are the
 * wait. Left to the retry strategy, a resume that ran out of retries went to the failure transport,
 * where it read as a lost run, although the resume sent after the append carried the execution.
 *
 * It carries nothing the run needs once it has waited long: whoever appends the outcome sends the
 * resume after it, and a worker that died before appending is redelivered its activity, whose
 * rerun appends and resumes. So the early resume is retried as recoverable, whatever `max_retries`
 * says, up to {@see self::MAX_RETRIES}; past that it is acknowledged, with an info log. It is the
 * Messenger counterpart of the Laravel resume job's `max_deferrals`.
 *
 * ponytail: reads the first handler's failure only; a resume has one handler.
 */
final readonly class EarlyResumeMiddleware implements MiddlewareInterface
{
    public const MAX_RETRIES = 10;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $e) {
            $early = $e->getPrevious();
            if (!$early instanceof ResumeArrivedBeforeItsOutcome) {
                throw $e;
            }

            $retries = $envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0;
            if ($retries < self::MAX_RETRIES) {
                throw new RecoverableMessageHandlingException($early->getMessage(), 0, $e);
            }

            $this->logger?->info('Durable: dropped an early resume of execution {execution} after {retries} retries; {awaited} is not journalled yet, and the resume sent after its append carries the run (DUR050, DUR052).', [
                'execution' => $early->executionId,
                'awaited' => $early->awaited->describe(),
                'retries' => $retries,
            ]);

            return $envelope;
        }
    }
}
