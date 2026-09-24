<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * What a starting Messenger worker consumes, as far as Durable is concerned, and with which
 * run-scoped behaviour. Both `durable:worker` and `messenger:consume` start a worker, and the first
 * runs the second without a console event: the worker's own events are the one place both reach.
 *
 * `messenger:consume` turns its options into subscribers it adds for the run only: the
 * services-reset listener unless `--no-reset`, a message-limit listener for `--limit`, a
 * failure-limit listener for `--failure-limit`. {@see hasRunListener()} reads them back.
 */
final class DurableWorkerInspection
{
    private const TEMPORAL_RECEIVERS = ['durable_workflows', 'durable_activities', 'durable_nexus'];

    public function __construct(
        private readonly ?SendersLocatorInterface $senders,
        private readonly ?ContainerInterface $receivers,
        private readonly ?string $activityTransport,
        private readonly bool $temporal,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    /**
     * @param list<string> $transportNames the worker's, from its metadata
     *
     * @return list<string> those Durable routes its messages to, or reads from on Temporal
     */
    public function durableTransports(array $transportNames): array
    {
        $durable = $this->temporal
            ? self::TEMPORAL_RECEIVERS
            : [...$this->routedTo(new ResumeWorkflowMessage('durable:inspection')), ...$this->routedTo(new FireWorkflowTimersMessage('durable:inspection')), ...(null === $this->activityTransport ? [] : [$this->activityTransport])];

        return array_values(array_intersect($transportNames, $durable));
    }

    public function isInMemory(string $transportName): bool
    {
        return true === $this->receivers?->has($transportName) && $this->receivers->get($transportName) instanceof InMemoryTransport;
    }

    /**
     * @param class-string $listenerClass
     * @param class-string $event
     */
    public function hasRunListener(string $listenerClass, string $event): bool
    {
        if (!method_exists($this->dispatcher, 'getListeners')) {
            return false;
        }
        foreach ($this->dispatcher->getListeners($event) as $listener) {
            if (\is_array($listener) && $listener[0] instanceof $listenerClass) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function routedTo(object $message): array
    {
        $names = [];
        foreach ($this->senders?->getSenders(new Envelope($message)) ?? [] as $name => $sender) {
            if (!$sender instanceof SyncTransport) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
