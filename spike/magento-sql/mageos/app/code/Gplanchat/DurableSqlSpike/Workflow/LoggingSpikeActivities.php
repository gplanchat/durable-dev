<?php

declare(strict_types=1);

namespace Gplanchat\DurableSqlSpike\Workflow;

/** Each real execution appends a line to var/log/spike-activities.log: the count of side effects, beside the journal's. */
final class LoggingSpikeActivities implements SpikeActivities
{
    public function charge(string $orderId, int $pauseSeconds): string
    {
        self::log("charge:start $orderId");
        sleep($pauseSeconds);
        self::log("charge:end $orderId");

        return "receipt-$orderId";
    }

    public function ship(string $orderId, string $approvedBy): string
    {
        self::log("ship $orderId by $approvedBy");

        return "shipped-$orderId";
    }

    private static function log(string $line): void
    {
        file_put_contents(BP . '/var/log/spike-activities.log', sprintf("%s pid=%d %s\n", date('H:i:s'), getmypid(), $line), FILE_APPEND);
    }
}
