<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Délai jusqu’au prochain minuteur non complété (pour {@see \Symfony\Component\Messenger\Stamp\DelayStamp}).
 */
final class TimerWakeDelayCalculator
{
    /**
     * @return int millisecondes jusqu’à {@see TimerScheduled::scheduledAt()} du prochain timer en attente, ou null si aucun
     */
    public static function millisecondsUntilNextTimerDue(EventStoreInterface $store, string $executionId, float $nowSeconds): ?int
    {
        $scheduled = [];
        $completed = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduled[$event->timerId()] = $event->scheduledAt();
            }
            if ($event instanceof TimerCompleted) {
                $completed[$event->timerId()] = true;
            }
        }

        $pending = [];
        foreach ($scheduled as $id => $at) {
            if (!isset($completed[$id])) {
                $pending[] = $at;
            }
        }

        if ([] === $pending) {
            return null;
        }

        $minDue = min($pending);
        $sec = max(0.0, $minDue - $nowSeconds);

        return (int) ceil($sec * 1000.0);
    }
}
