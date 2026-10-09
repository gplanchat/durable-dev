<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Ui;

use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\SystemClock;

/**
 * The line of a run no worker has picked up (#818), worded once by the core as a key and its
 * parameters (#850) and translated here.
 *
 * Magento's dictionary is keyed by the English phrase, so the core's key maps to a literal phrase:
 * the phrase is what `i18n/fr_FR.csv` translates.
 */
final class WaitingForWorker
{
    private function __construct() {}

    public static function of(WorkflowRunDescription $run, ?\DateTimeImmutable $now = null): ?string
    {
        if (null === $run->waitingForWorkerSince) {
            return null;
        }

        $message = RunDashboard::waitingForWorker($run->waitingForWorkerSince, $now ?? (new SystemClock())->now())['localizedWaitingForWorker'];

        return (string) __('waiting for a worker · %1', $message->params['elapsed']);
    }
}
