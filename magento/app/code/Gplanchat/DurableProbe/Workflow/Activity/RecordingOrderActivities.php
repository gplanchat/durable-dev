<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * The implementation that leaves a trace, so that "the card is not charged twice" is a measurement
 * and not a belief.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class RecordingOrderActivities implements SlowOrderActivities
{
    public const CHARGES_LOG = 'durable-charges.log';

    public function __construct(
        private readonly DirectoryList $directories,
        private readonly File $filesystem,
    ) {}

    public function charge(string $orderId): string
    {
        $this->filesystem->filePutContents(
            $this->directories->getPath(DirectoryList::LOG) . '/' . self::CHARGES_LOG,
            sprintf("%s %s pid=%d\n", date('H:i:s'), $orderId, getmypid()),
            FILE_APPEND,
        );

        return 'charge:' . $orderId;
    }

    public function reserveStock(string $orderId, int $pauseSeconds): string
    {
        // The window during which the process is killed. The card is already charged, the stock
        // is not: this is exactly the moment OST003 describes.
        sleep($pauseSeconds);

        return 'reserve:' . $orderId;
    }

    public function notifyCustomer(string $receipt): string
    {
        return 'notify:' . $receipt;
    }
}
