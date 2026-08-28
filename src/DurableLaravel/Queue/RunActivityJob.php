<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Une activité, sur la file que l'application draine déjà.
 *
 * Le job ne fait rien lui-même : il porte le message et le rend au processeur du cœur, celui-là
 * même que le handler Messenger du bundle Symfony appelle. Les délais d'attente, le journal, la
 * reprise du workflow et la politique de retentative vivent là, une fois, pour tous les hôtes.
 *
 * **Aucun trait de file.** `Queueable` et `InteractsWithQueue` servent à `dispatch()` et à
 * `release()` ; ce job est poussé par le transport et ne se remet jamais en file lui-même. Le job
 * de reprise, lui, en aura besoin — c'est là que le paquet prendra `illuminate/queue`.
 */
final class RunActivityJob implements ShouldQueue
{
    public function __construct(
        public readonly ActivityMessage $message,
    ) {}

    public function handle(ActivityMessageProcessor $processor): void
    {
        $processor->process($this->message);
    }
}
