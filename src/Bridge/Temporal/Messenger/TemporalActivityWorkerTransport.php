<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Receive-only: one {@see get()} long-polls a Temporal activity task and executes it via {@see TemporalActivityWorker}.
 */
final class TemporalActivityWorkerTransport implements TransportInterface
{
    use ReceiveOnlyTransport;

    public function __construct(
        private readonly TemporalActivityWorker $worker,
    ) {}

    public function get(): iterable
    {
        $this->worker->pollOnce();

        return [];
    }

    protected function receiveOnlyName(): string
    {
        return 'temporal activity worker';
    }
}
