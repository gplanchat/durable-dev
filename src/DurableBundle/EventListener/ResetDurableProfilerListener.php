<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\EventListener;

use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Vide la trace en début de requête HTTP principale pour éviter la fuite entre requêtes.
 */
final class ResetDurableProfilerListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly DurableExecutionTrace $trace,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 1024]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->trace->reset();
    }
}
