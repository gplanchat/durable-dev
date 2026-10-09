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
 * Magento's dictionary is keyed by the English phrase, so each core key maps to a literal phrase:
 * the phrase is what `i18n/fr_FR.csv` translates, and a key this module does not know shows the
 * core's English string.
 */
final class WaitingForWorker
{
    private function __construct() {}

    public static function of(WorkflowRunDescription $run): ?string
    {
        if (null === $run->waitingForWorkerSince) {
            return null;
        }

        $line = RunDashboard::waitingForWorker($run->waitingForWorkerSince, (new SystemClock())->now());
        $message = $line['localizedWaitingForWorker'];

        return match ($message->key) {
            'run.waiting_for_worker' => (string) __('waiting for a worker · %1', $message->params['elapsed']),
            default => $line['waitingForWorker'],
        };
    }
}
